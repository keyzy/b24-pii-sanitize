<?php

declare(strict_types=1);

namespace Keyzy\Pii;

final class Verifier
{
    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
        private readonly StateStore $store,
        private readonly Console $console,
        private readonly string $runId,
    ) {
    }

    /**
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param callable(array<string,mixed>&):void $checkpoint
     */
    public function run(array &$progress, array &$state, array $plan, callable $checkpoint): void
    {
        $targets = $this->buildTargets($plan);
        $targetIndex = (int)($progress['target_index'] ?? 0);
        $emailPattern = '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}';
        $phonePattern = '\\+?[0-9][0-9 ()-]{8,}[0-9]';

        for ($index = $targetIndex, $count = count($targets); $index < $count; $index++) {
            [$table, $column] = $targets[$index];
            $quotedTable = $this->schema->quoteTable($table);
            $quotedColumn = $this->schema->quoteColumn($table, $column);
            $emailLiteral = $this->schema->literal($emailPattern);
            $allowedEmailLiteral = $this->schema->literal('@example\\.invalid');
            $phoneLiteral = $this->schema->literal($phonePattern);
            $row = $this->context->connection->query(
                "SELECT "
                . "SUM(CASE WHEN {$quotedColumn} REGEXP {$emailLiteral} AND {$quotedColumn} NOT REGEXP {$allowedEmailLiteral} THEN 1 ELSE 0 END) AS EMAIL_HITS, "
                . "SUM(CASE WHEN {$quotedColumn} REGEXP {$phoneLiteral} THEN 1 ELSE 0 END) AS PHONE_HITS "
                . "FROM {$quotedTable}"
            )->fetch();
            $emailHits = (int)($row['EMAIL_HITS'] ?? 0);
            $phoneHits = (int)($row['PHONE_HITS'] ?? 0);
            if ($emailHits > 0 || $phoneHits > 0) {
                $this->store->appendJsonLine($this->runId, 'verification_findings.jsonl', [
                    'table' => $table,
                    'column' => $column,
                    'email_hits' => $emailHits,
                    'phone_hits' => $phoneHits,
                    'contains_values' => false,
                ]);
                $progress['columns_with_findings'] = (int)($progress['columns_with_findings'] ?? 0) + 1;
            }
            $progress['email_hits'] = (int)($progress['email_hits'] ?? 0) + $emailHits;
            $progress['phone_hits'] = (int)($progress['phone_hits'] ?? 0) + $phoneHits;
            $progress['target_index'] = $index + 1;
            $progress['processed'] = $index + 1;
            $checkpoint($state);
        }

        if (($progress['invariants_done'] ?? false) !== true) {
            $invariants = $this->checkInvariants($plan);
            $progress['invariants'] = $invariants;
            $progress['invariants_done'] = true;
            $checkpoint($state);
        }

        $invariantHits = 0;
        foreach ((array)($progress['invariants'] ?? []) as $invariant) {
            $invariantHits += (int)($invariant['unexpected_rows'] ?? 0);
        }
        $emailHits = (int)($progress['email_hits'] ?? 0);
        $phoneHits = (int)($progress['phone_hits'] ?? 0);
        $status = $invariantHits === 0 && $emailHits === 0 && $phoneHits === 0
            ? 'passed'
            : 'warnings';

        $report = [
            'version' => 1,
            'run_id' => $this->runId,
            'completed_at' => date(DATE_ATOM),
            'status' => $status,
            'scanned_text_columns' => count($targets),
            'columns_with_findings' => (int)($progress['columns_with_findings'] ?? 0),
            'email_hits' => $emailHits,
            'phone_hits' => $phoneHits,
            'invariants' => $progress['invariants'] ?? [],
            'notes' => [
                'Значения из БД в отчет не записываются.',
                'Проверка телефонов широкая и может считать длинные номера документов или технические последовательности.',
                'warnings означает необходимость ручной проверки таблиц/колонок из verification_findings.jsonl.',
            ],
        ];
        $this->store->saveArtifact($this->runId, 'verification.json', $report);
        $progress['verification_status'] = $status;
        $this->console->info("Проверка остаточных ПДн завершена со статусом: {$status}");
        $checkpoint($state);
    }

    /** @param array<string,mixed> $plan @return list<array{0:string,1:string}> */
    private function buildTargets(array $plan): array
    {
        $includedTables = [];
        foreach ((array)($plan['operations'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            if (isset($operation['table']) && $this->schema->hasTable((string)$operation['table'])) {
                $includedTables[(string)$operation['table']] = true;
            }
            if (($operation['type'] ?? '') === 'special') {
                $name = (string)($operation['name'] ?? '');
                $specialTables = match ($name) {
                    'crm_email_activities' => ['b_crm_act'],
                    'sanitize_crm_activity_structures' => ['b_crm_act'],
                    'sanitize_crm_timeline_structures' => [
                        'b_crm_timeline',
                        'b_crm_timeline_rest_app_layout_blocks',
                    ],
                    'sanitize_crm_location_addresses' => ['b_crm_addr', 'b_location_address', 'b_location_addr_fld'],
                    'sanitize_options' => ['b_option'],
                    'delete_files' => ['b_file'],
                    default => [],
                };
                foreach ($specialTables as $table) {
                    if ($this->schema->hasTable($table)) {
                        $includedTables[$table] = true;
                    }
                }
                if ($name === 'user_fields') {
                    foreach ($this->schema->tablesMatching('/^b_(uts_|utm_|crm_dynamic_items_)/') as $table) {
                        $includedTables[$table] = true;
                    }
                }
            }
        }
        $targets = [];
        $tables = array_keys($includedTables);
        sort($tables, SORT_STRING);
        $structuredColumns = [
            'b_crm_act' => ['SETTINGS' => true, 'PROVIDER_PARAMS' => true, 'PROVIDER_DATA' => true],
            'b_crm_timeline' => ['SETTINGS' => true],
            'b_crm_timeline_rest_app_layout_blocks' => ['LAYOUT' => true],
        ];
        foreach ($tables as $table) {
            foreach (array_keys($this->schema->columns($table)) as $column) {
                if (isset($structuredColumns[$table][$column])) {
                    continue;
                }
                if ($this->schema->isTextColumn($table, $column)) {
                    $targets[] = [$table, $column];
                }
            }
        }

        return $targets;
    }

    /** @param array<string,mixed> $plan @return list<array{name:string,unexpected_rows:int}> */
    private function checkInvariants(array $plan): array
    {
        $selectedBlocks = array_values((array)($plan['selected_blocks'] ?? []));
        $checks = [];
        if (in_array('crm_core', $selectedBlocks, true)
            && $this->schema->hasTable('b_crm_company')
            && $this->schema->hasColumn('b_crm_company', 'ID')
            && $this->schema->hasColumn('b_crm_company', 'TITLE')) {
            $table = $this->schema->quoteTable('b_crm_company');
            $id = $this->schema->quoteColumn('b_crm_company', 'ID');
            $title = $this->schema->quoteColumn('b_crm_company', 'TITLE');
            $count = (int)$this->context->connection->queryScalar(
                "SELECT COUNT(*) FROM {$table} WHERE {$title} IS NULL OR {$title} <> CONCAT('Компания ', CAST({$id} AS CHAR))"
            );
            $checks[] = ['name' => 'company_titles', 'unexpected_rows' => $count];
        }
        if ((in_array('crm_core', $selectedBlocks, true) || in_array('crm_entities', $selectedBlocks, true))
            && $this->schema->hasTable('b_crm_field_multi')
            && $this->schema->hasColumn('b_crm_field_multi', 'TYPE_ID')) {
            $where = [
                ['column' => 'TYPE_ID', 'op' => 'in', 'values' => ['PHONE', 'EMAIL', 'WEB', 'IM', 'LINK']],
            ];
            $checks[] = [
                'name' => 'crm_multifields',
                'unexpected_rows' => $this->schema->countRows('b_crm_field_multi', $where),
            ];
        }
        if (in_array('mail', $selectedBlocks, true)
            && $this->schema->hasTable('b_crm_act')
            && $this->schema->hasColumn('b_crm_act', 'TYPE_ID')) {
            $checks[] = [
                'name' => 'crm_email_activities',
                'unexpected_rows' => $this->schema->countRows('b_crm_act', [['column' => 'TYPE_ID', 'op' => 'eq', 'value' => 4]]),
            ];
        }
        foreach (['b_mail_message' => ['mail_messages', 'mail'], 'b_im_message' => ['im_messages', 'chats']] as $table => [$name, $block]) {
            if (in_array($block, $selectedBlocks, true) && $this->schema->hasTable($table)) {
                $checks[] = ['name' => $name, 'unexpected_rows' => $this->schema->countRows($table)];
            }
        }
        if (in_array('tasks_social_calendar', $selectedBlocks, true)
            && $this->schema->hasTable('b_forum_message')
            && $this->schema->hasColumn('b_forum_message', 'POST_MESSAGE')
            && $this->schema->hasColumn('b_forum_message', 'SERVICE_TYPE')
            && $this->schema->hasColumn('b_forum_message', 'SERVICE_DATA')) {
            $table = $this->schema->quoteTable('b_forum_message');
            $postMessage = $this->schema->quoteColumn('b_forum_message', 'POST_MESSAGE');
            $serviceType = $this->schema->quoteColumn('b_forum_message', 'SERVICE_TYPE');
            $serviceData = $this->schema->quoteColumn('b_forum_message', 'SERVICE_DATA');
            $checks[] = [
                'name' => 'task_service_comment_text_contract',
                'unexpected_rows' => (int)$this->context->connection->queryScalar(
                    "SELECT COUNT(*) FROM {$table} WHERE {$serviceType} > 0 "
                    . "AND {$postMessage} IS NULL AND {$serviceData} IS NULL"
                ),
            ];
        }
        if ($this->context->runtimeMode === 'standalone-mysql'
            && in_array('files_disk', $selectedBlocks, true)
            && $this->schema->hasTable('b_file')) {
            $checks[] = ['name' => 'standalone_b_file', 'unexpected_rows' => $this->schema->countRows('b_file')];
        }
        $this->appendCrmHistoryChecks($checks, $selectedBlocks, $plan);
        $this->appendMarketingChecks($checks, $selectedBlocks);
        $this->appendAddressChecks($checks, $selectedBlocks);
        $this->appendZeroValueChecks($checks, $plan);
        return $checks;
    }

    /** @param list<array{name:string,unexpected_rows:int}> $checks @param list<string> $selectedBlocks @param array<string,mixed> $plan */
    private function appendCrmHistoryChecks(array &$checks, array $selectedBlocks, array $plan): void
    {
        if (!in_array('crm_history', $selectedBlocks, true)) {
            return;
        }

        if ($this->schema->hasTable('b_crm_timeline')) {
            $expected = $this->estimatedRowsForTable($plan, 'b_crm_timeline');
            $actual = $this->schema->countRows('b_crm_timeline');
            $checks[] = [
                'name' => 'crm_timeline_not_erased',
                'unexpected_rows' => $expected > 0 && $actual === 0 ? $expected : 0,
            ];
            $checks[] = [
                'name' => 'crm_timeline_structured_payloads',
                'unexpected_rows' => $this->countStructuredPayloadsNeedingSanitization(
                    'b_crm_timeline',
                    ['SETTINGS' => 'serialized_required'],
                ),
            ];
        }

        if ($this->schema->hasTable('b_crm_act')) {
            $checks[] = [
                'name' => 'crm_activity_structured_payloads',
                'unexpected_rows' => $this->countStructuredPayloadsNeedingSanitization(
                    'b_crm_act',
                    [
                        'SETTINGS' => 'serialized',
                        'PROVIDER_PARAMS' => 'serialized',
                        'PROVIDER_DATA' => 'encoded',
                    ],
                ),
            ];
        }

        if ($this->schema->hasTable('b_crm_timeline_rest_app_layout_blocks')) {
            $checks[] = [
                'name' => 'crm_timeline_layout_payloads',
                'unexpected_rows' => $this->countStructuredPayloadsNeedingSanitization(
                    'b_crm_timeline_rest_app_layout_blocks',
                    ['LAYOUT' => 'encoded'],
                ),
            ];
        }

        if ($this->schema->hasTable('b_crm_timeline')
            && $this->schema->hasTable('b_crm_timeline_bind')
            && $this->schema->hasColumn('b_crm_timeline_bind', 'OWNER_ID')
            && $this->schema->hasColumn('b_crm_timeline', 'ID')) {
            $bindTable = $this->schema->quoteTable('b_crm_timeline_bind');
            $timelineTable = $this->schema->quoteTable('b_crm_timeline');
            $ownerId = $this->schema->quoteColumn('b_crm_timeline_bind', 'OWNER_ID');
            $timelineId = $this->schema->quoteColumn('b_crm_timeline', 'ID');
            $checks[] = [
                'name' => 'crm_timeline_orphan_bindings',
                'unexpected_rows' => (int)$this->context->connection->queryScalar(
                    "SELECT COUNT(*) FROM {$bindTable} b LEFT JOIN {$timelineTable} t "
                    . "ON t.{$timelineId} = b.{$ownerId} WHERE t.{$timelineId} IS NULL"
                ),
            ];
        }

        $orphanAuxiliaryRows = 0;
        if ($this->schema->hasTable('b_crm_timeline_note')
            && $this->schema->hasColumn('b_crm_timeline_note', 'ITEM_ID')
            && $this->schema->hasColumn('b_crm_timeline_note', 'ITEM_TYPE')) {
            $noteTable = $this->schema->quoteTable('b_crm_timeline_note');
            $itemId = $this->schema->quoteColumn('b_crm_timeline_note', 'ITEM_ID');
            $itemType = $this->schema->quoteColumn('b_crm_timeline_note', 'ITEM_TYPE');
            if ($this->schema->hasTable('b_crm_timeline')) {
                $timelineTable = $this->schema->quoteTable('b_crm_timeline');
                $timelineId = $this->schema->quoteColumn('b_crm_timeline', 'ID');
                $orphanAuxiliaryRows += (int)$this->context->connection->queryScalar(
                    "SELECT COUNT(*) FROM {$noteTable} n LEFT JOIN {$timelineTable} t "
                    . "ON t.{$timelineId} = n.{$itemId} WHERE n.{$itemType} = 1 AND t.{$timelineId} IS NULL"
                );
            }
            if ($this->schema->hasTable('b_crm_act')) {
                $activityTable = $this->schema->quoteTable('b_crm_act');
                $activityId = $this->schema->quoteColumn('b_crm_act', 'ID');
                $orphanAuxiliaryRows += (int)$this->context->connection->queryScalar(
                    "SELECT COUNT(*) FROM {$noteTable} n LEFT JOIN {$activityTable} a "
                    . "ON a.{$activityId} = n.{$itemId} WHERE n.{$itemType} = 2 AND a.{$activityId} IS NULL"
                );
            }
        }
        if ($this->schema->hasTable('b_crm_timeline_rest_app_layout_blocks')
            && $this->schema->hasColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_ID')
            && $this->schema->hasColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_TYPE')) {
            $layoutTable = $this->schema->quoteTable('b_crm_timeline_rest_app_layout_blocks');
            $itemId = $this->schema->quoteColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_ID');
            $itemType = $this->schema->quoteColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_TYPE');
            if ($this->schema->hasTable('b_crm_timeline')) {
                $timelineTable = $this->schema->quoteTable('b_crm_timeline');
                $timelineId = $this->schema->quoteColumn('b_crm_timeline', 'ID');
                $orphanAuxiliaryRows += (int)$this->context->connection->queryScalar(
                    "SELECT COUNT(*) FROM {$layoutTable} l LEFT JOIN {$timelineTable} t "
                    . "ON t.{$timelineId} = l.{$itemId} WHERE l.{$itemType} = 2 AND t.{$timelineId} IS NULL"
                );
            }
            if ($this->schema->hasTable('b_crm_act')) {
                $activityTable = $this->schema->quoteTable('b_crm_act');
                $activityId = $this->schema->quoteColumn('b_crm_act', 'ID');
                $orphanAuxiliaryRows += (int)$this->context->connection->queryScalar(
                    "SELECT COUNT(*) FROM {$layoutTable} l LEFT JOIN {$activityTable} a "
                    . "ON a.{$activityId} = l.{$itemId} WHERE l.{$itemType} = 1 AND a.{$activityId} IS NULL"
                );
            }
        }
        $checks[] = [
            'name' => 'crm_timeline_orphan_auxiliary_rows',
            'unexpected_rows' => $orphanAuxiliaryRows,
        ];
    }

    /** @param array<string,mixed> $plan */
    private function estimatedRowsForTable(array $plan, string $table): int
    {
        $estimated = 0;
        foreach ((array)($plan['operations'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }
            if (($operation['table'] ?? null) === $table) {
                $estimated = max($estimated, (int)($operation['estimated_rows'] ?? 0));
            }
            foreach ((array)($operation['targets'] ?? []) as $target) {
                if (is_array($target) && ($target['table'] ?? null) === $table) {
                    $estimated = max($estimated, (int)($target['estimated_rows'] ?? 0));
                }
            }
        }
        return $estimated;
    }

    /** @param array<string,string> $columns */
    private function countStructuredPayloadsNeedingSanitization(string $table, array $columns): int
    {
        $available = [];
        foreach ($columns as $column => $mode) {
            if ($this->schema->hasColumn($table, $column)) {
                $available[$column] = $mode;
            }
        }
        if ($available === []) {
            return 0;
        }

        $quotedColumns = array_map(
            fn(string $column): string => $this->schema->quoteColumn($table, $column),
            array_keys($available),
        );
        $result = $this->context->connection->query(
            'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $this->schema->quoteTable($table)
        );
        $unexpected = 0;
        while ($row = $result->fetch()) {
            foreach ($available as $column => $mode) {
                $raw = (string)($row[$column] ?? '');
                $sanitized = match ($mode) {
                    'serialized' => CrmStructuredPayloadSanitizer::sanitizeSerializedArray($raw),
                    'serialized_required' => CrmStructuredPayloadSanitizer::sanitizeSerializedArray($raw, true),
                    'encoded' => CrmStructuredPayloadSanitizer::sanitizeEncodedArray($raw),
                    default => '',
                };
                if ($sanitized !== $raw) {
                    $unexpected++;
                    break;
                }
            }
        }
        return $unexpected;
    }

    /** @param list<array{name:string,unexpected_rows:int}> $checks @param list<string> $selectedBlocks */
    private function appendMarketingChecks(array &$checks, array $selectedBlocks): void
    {
        if (!in_array('marketing', $selectedBlocks, true)) {
            return;
        }

        $remainingRows = 0;
        foreach ($this->schema->tablesMatching(BlockCatalog::MARKETING_OPERATIONAL_TABLE_PATTERN) as $table) {
            $remainingRows += $this->schema->countRows($table);
        }
        $checks[] = [
            'name' => 'marketing_operational_rows',
            'unexpected_rows' => $remainingRows,
        ];
    }

    /** @param list<array{name:string,unexpected_rows:int}> $checks @param array<string,mixed> $plan */
    private function appendZeroValueChecks(array &$checks, array $plan): void
    {
        $financialBlocks = ['crm_financials' => true, 'catalog_prices' => true];
        $numericTypes = [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'float', 'double', 'real', 'bit',
        ];

        foreach ((array)($plan['operations'] ?? []) as $operation) {
            if (!is_array($operation)
                || ($operation['type'] ?? '') !== 'update'
                || !isset($financialBlocks[(string)($operation['block'] ?? '')])) {
                continue;
            }
            $table = (string)($operation['table'] ?? '');
            if (!$this->schema->hasTable($table)) {
                continue;
            }

            $conditions = [];
            foreach ((array)($operation['set'] ?? []) as $column => $specification) {
                if (!is_array($specification)
                    || ($specification['mode'] ?? '') !== 'literal'
                    || !array_key_exists('value', $specification)
                    || !is_numeric($specification['value'])
                    || (float)$specification['value'] !== 0.0
                    || !$this->schema->hasColumn($table, (string)$column)) {
                    continue;
                }
                $dataType = strtolower((string)($this->schema->column($table, (string)$column)['DATA_TYPE'] ?? ''));
                if (!in_array($dataType, $numericTypes, true)) {
                    continue;
                }
                $quotedColumn = $this->schema->quoteColumn($table, (string)$column);
                $conditions[] = "{$quotedColumn} IS NULL OR {$quotedColumn} <> 0";
            }
            if ($conditions === []) {
                continue;
            }

            $where = $this->schema->buildWhere($table, (array)($operation['where'] ?? []));
            $where .= ($where === '' ? ' WHERE ' : ' AND ')
                . '((' . implode(') OR (', $conditions) . '))';
            $operationId = preg_replace('/[^A-Za-z0-9_]+/', '_', (string)($operation['id'] ?? 'operation'));
            $checks[] = [
                'name' => 'zero_values_' . $table . '_' . $operationId,
                'unexpected_rows' => (int)$this->context->connection->queryScalar(
                    'SELECT COUNT(*) FROM ' . $this->schema->quoteTable($table) . $where
                ),
            ];
        }
    }

    /** @param list<array{name:string,unexpected_rows:int}> $checks @param list<string> $selectedBlocks */
    private function appendAddressChecks(array &$checks, array $selectedBlocks): void
    {
        $targets = [];
        if (in_array('crm_core', $selectedBlocks, true)) {
            $targets['b_crm_contact'] = '/^(ADDRESS|REG_ADDRESS)/i';
            $targets['b_crm_company'] = '/^(ADDRESS|REG_ADDRESS)/i';
        }
        if (in_array('crm_entities', $selectedBlocks, true)) {
            $targets['b_crm_lead'] = '/^(ADDRESS|REG_ADDRESS)/i';
        }
        if (in_array('crm_requisites', $selectedBlocks, true)) {
            foreach (['b_crm_requisite_addr', 'b_crm_addr', 'b_crm_entity_addr'] as $table) {
                $targets[$table] = '/(ADDRESS|CITY|POSTAL|REGION|PROVINCE|COUNTRY|LOC_ADDR)/i';
            }
        }

        foreach ($targets as $table => $pattern) {
            if (!$this->schema->hasTable($table)) {
                continue;
            }
            $columns = [];
            foreach (array_keys($this->schema->columns($table)) as $column) {
                if (preg_match($pattern, $column) === 1) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $checks[] = [
                    'name' => 'address_fields_' . $table,
                    'unexpected_rows' => $this->countRowsWithNonNeutralValues($table, $columns),
                ];
            }
        }

        if (!in_array('crm_requisites', $selectedBlocks, true)) {
            return;
        }
        $this->appendLinkedLocationChecks($checks);
    }

    /** @param list<array{name:string,unexpected_rows:int}> $checks */
    private function appendLinkedLocationChecks(array &$checks): void
    {
        $linkTable = 'b_location_addr_link';
        if (!$this->schema->hasTable($linkTable)
            || !$this->schema->hasColumn($linkTable, 'ADDRESS_ID')
            || !$this->schema->hasColumn($linkTable, 'ENTITY_TYPE')) {
            return;
        }

        $quotedLink = $this->schema->quoteTable($linkTable);
        $linkAddress = $this->schema->quoteColumn($linkTable, 'ADDRESS_ID');
        $linkType = $this->schema->quoteColumn($linkTable, 'ENTITY_TYPE');
        $crmTypePattern = $this->schema->literal('^CRM(_|$)');

        $fieldTable = 'b_location_addr_fld';
        if ($this->schema->hasTable($fieldTable) && $this->schema->hasColumn($fieldTable, 'ADDRESS_ID')) {
            $conditions = [];
            foreach (['VALUE', 'VALUE_NORMALIZED'] as $column) {
                if ($this->schema->hasColumn($fieldTable, $column)) {
                    $conditions[] = $this->nonNeutralCondition($fieldTable, $column, 'f');
                }
            }
            if ($conditions !== []) {
                $fieldAddress = $this->schema->quoteColumn($fieldTable, 'ADDRESS_ID');
                $checks[] = [
                    'name' => 'crm_location_address_fields',
                    'unexpected_rows' => (int)$this->context->connection->queryScalar(
                        "SELECT COUNT(DISTINCT l.{$linkAddress}) FROM {$quotedLink} l "
                        . 'INNER JOIN ' . $this->schema->quoteTable($fieldTable) . " f ON f.{$fieldAddress} = l.{$linkAddress} "
                        . "WHERE l.{$linkType} REGEXP {$crmTypePattern} AND (" . implode(' OR ', $conditions) . ')'
                    ),
                ];
            }
        }

        $addressTable = 'b_location_address';
        if ($this->schema->hasTable($addressTable) && $this->schema->hasColumn($addressTable, 'ID')) {
            $conditions = [];
            foreach (['LOCATION_ID', 'LATITUDE', 'LONGITUDE'] as $column) {
                if ($this->schema->hasColumn($addressTable, $column)) {
                    $conditions[] = $this->nonNeutralCondition($addressTable, $column, 'a');
                }
            }
            if ($conditions !== []) {
                $addressId = $this->schema->quoteColumn($addressTable, 'ID');
                $checks[] = [
                    'name' => 'crm_location_address_coordinates',
                    'unexpected_rows' => (int)$this->context->connection->queryScalar(
                        "SELECT COUNT(DISTINCT l.{$linkAddress}) FROM {$quotedLink} l "
                        . 'INNER JOIN ' . $this->schema->quoteTable($addressTable) . " a ON a.{$addressId} = l.{$linkAddress} "
                        . "WHERE l.{$linkType} REGEXP {$crmTypePattern} AND (" . implode(' OR ', $conditions) . ')'
                    ),
                ];
            }
        }
    }

    /** @param list<string> $columns */
    private function countRowsWithNonNeutralValues(string $table, array $columns): int
    {
        $conditions = [];
        foreach ($columns as $column) {
            if ($this->schema->hasColumn($table, $column)) {
                $conditions[] = $this->nonNeutralCondition($table, $column);
            }
        }
        if ($conditions === []) {
            return 0;
        }
        return (int)$this->context->connection->queryScalar(
            'SELECT COUNT(*) FROM ' . $this->schema->quoteTable($table)
            . ' WHERE (' . implode(' OR ', $conditions) . ')'
        );
    }

    private function nonNeutralCondition(string $table, string $column, ?string $alias = null): string
    {
        $metadata = $this->schema->column($table, $column);
        $quoted = ($alias === null ? '' : $alias . '.') . $this->schema->quoteColumn($table, $column);
        $type = strtolower((string)($metadata['DATA_TYPE'] ?? ''));
        if (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
            return 'COALESCE(' . $quoted . ', ' . $this->schema->literal('') . ') <> ' . $this->schema->literal('');
        }
        if (($metadata['IS_NULLABLE'] ?? 'NO') === 'YES') {
            return $quoted . ' IS NOT NULL';
        }
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit'], true)) {
            return $quoted . ' <> 0';
        }
        return $quoted . ' IS NOT NULL';
    }
}
