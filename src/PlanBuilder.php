<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class PlanBuilder
{
    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
        private readonly StateStore $store,
        private readonly Console $console,
    ) {
    }

    /**
     * @param list<string> $selectedBlocks
     * @return array{plan:array<string,mixed>,inventory:array<string,mixed>}
     */
    public function build(
        string $runId,
        array $selectedBlocks,
        string $chatMode,
        string $nonAdminLoginMode = 'anonymize',
    ): array
    {
        $catalog = BlockCatalog::all($chatMode, $nonAdminLoginMode);
        $selectedSet = array_fill_keys($selectedBlocks, true);
        $operations = [];
        $skipped = [];
        $sequence = 0;
        $ufSummary = null;

        foreach ($selectedBlocks as $blockId) {
            if (!isset($catalog[$blockId])) {
                throw new RuntimeException("Неизвестный блок: {$blockId}");
            }
            foreach ($catalog[$blockId]['operations'] as $definition) {
                $requiredBlock = $definition['requires_block'] ?? null;
                if (is_string($requiredBlock) && !isset($selectedSet[$requiredBlock])) {
                    $skipped[] = [
                        'block' => $blockId,
                        'reason' => "Операция требует выбранный блок {$requiredBlock}.",
                        'type' => $definition['type'],
                    ];
                    continue;
                }
                $runtimeModes = $definition['runtime_modes'] ?? null;
                if (is_array($runtimeModes) && !in_array($this->context->runtimeMode, $runtimeModes, true)) {
                    $skipped[] = [
                        'block' => $blockId,
                        'reason' => 'Операция не применяется в режиме ' . $this->context->runtimeMode . '.',
                        'type' => $definition['type'],
                        'table' => $definition['table'] ?? null,
                    ];
                    continue;
                }

                if (($definition['type'] ?? '') === 'special') {
                    $special = $this->resolveSpecial($definition, $blockId, $runId, $selectedBlocks);
                    if ($special === null) {
                        $skipped[] = ['block' => $blockId, 'reason' => 'Для специальной операции нет подходящих таблиц.', 'type' => 'special', 'name' => $definition['name']];
                        continue;
                    }
                    if (($definition['name'] ?? '') === 'user_fields') {
                        $ufSummary = $special['uf_summary'] ?? null;
                    }
                    $special['_sequence'] = $sequence++;
                    $operations[] = $special;
                    continue;
                }

                $tables = $this->resolveTables($definition);
                if ($tables === []) {
                    $skipped[] = [
                        'block' => $blockId,
                        'reason' => 'Таблица или таблицы по маске отсутствуют.',
                        'type' => $definition['type'],
                        'table' => $definition['table'] ?? null,
                        'table_pattern' => $definition['table_pattern'] ?? null,
                    ];
                    continue;
                }

                foreach ($tables as $table) {
                    $resolved = $this->resolveTableOperation($definition, $blockId, $table);
                    if ($resolved === null) {
                        $skipped[] = [
                            'block' => $blockId,
                            'reason' => 'Нет подходящих колонок или отсутствует колонка условия.',
                            'type' => $definition['type'],
                            'table' => $table,
                        ];
                        continue;
                    }
                    $resolved['_sequence'] = $sequence++;
                    $operations[] = $resolved;
                }
            }
        }

        usort($operations, static function (array $left, array $right): int {
            $stage = ((int)$left['stage']) <=> ((int)$right['stage']);
            return $stage !== 0 ? $stage : ((int)$left['_sequence'] <=> (int)$right['_sequence']);
        });

        $inventoryOperations = [];
        foreach ($operations as $index => &$operation) {
            $operation['id'] = sprintf('%03d_%s', $index + 1, substr(hash('sha256', json_encode($operation, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 12));
            unset($operation['_sequence']);
            $inventoryOperations[] = [
                'id' => $operation['id'],
                'block' => $operation['block'],
                'type' => $operation['type'],
                'name' => $operation['name'] ?? null,
                'table' => $operation['table'] ?? null,
                'columns' => array_keys((array)($operation['set'] ?? [])) ?: ($operation['columns'] ?? []),
                'estimated_rows' => $operation['estimated_rows'] ?? null,
            ];
        }
        unset($operation);

        $plan = [
            'version' => 1,
            'run_id' => $runId,
            'generated_at' => date(DATE_ATOM),
            'database' => [
                'name' => $this->context->databaseName,
                'host' => $this->context->databaseHost,
                'fingerprint' => $this->context->fingerprint,
                'document_root' => $this->context->documentRoot,
                'runtime_mode' => $this->context->runtimeMode,
            ],
            'selected_blocks' => $selectedBlocks,
            'chat_mode' => $chatMode,
            'non_admin_login_mode' => $nonAdminLoginMode,
            'operations' => $operations,
        ];
        $inventory = [
            'version' => 1,
            'run_id' => $runId,
            'generated_at' => date(DATE_ATOM),
            'selected_blocks' => $selectedBlocks,
            'non_admin_login_mode' => $nonAdminLoginMode,
            'operations' => $inventoryOperations,
            'skipped' => $skipped,
            'user_fields_summary' => $ufSummary,
            'note' => 'Сохраняются только имена таблиц/колонок и количества строк; значения ПДн не выгружаются.',
        ];

        $plan['integrity_hash'] = hash(
            'sha256',
            json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        $this->store->saveArtifact($runId, 'plan.json', $plan);
        $this->store->saveArtifact($runId, 'inventory.json', $inventory);
        if (in_array('users', $selectedBlocks, true)) {
            $token = strtolower(substr(hash('sha256', $runId), 0, 10));
            $this->store->saveArtifact($runId, 'access_notes.json', [
                'version' => 3,
                'login_strategy' => $nonAdminLoginMode === 'anonymize'
                    ? 'preserve_administrator_group_1'
                    : 'preserve_all',
                'non_admin_login_mode' => $nonAdminLoginMode,
                'login_pattern_non_administrators' => $nonAdminLoginMode === 'anonymize'
                    ? "pii_{$token}_user_{ID}"
                    : null,
                'email_pattern' => "pii_{$token}_user_{ID}@example.invalid",
                'preserved_administrator_group_id' => 1,
                'administrator_group_lookup_available' => $this->administratorGroupLookupAvailable(),
                'preserved_administrator_user_ids' => $this->administratorUserIds(),
                'password_hashes' => 'Сохраняются без изменения.',
                'note' => $nonAdminLoginMode === 'anonymize'
                    ? 'Логины пользователей административной группы сохраняются; логины остальных заменяются. Для входа администратора используйте прежние логин и пароль.'
                    : 'Логины всех пользователей сохраняются без изменения.',
            ]);
        }
        return ['plan' => $plan, 'inventory' => $inventory];
    }

    /** @return list<int> */
    private function administratorUserIds(): array
    {
        if (!$this->administratorGroupLookupAvailable()) {
            return [];
        }

        $table = $this->schema->quoteTable('b_user_group');
        $userId = $this->schema->quoteColumn('b_user_group', 'USER_ID');
        $groupId = $this->schema->quoteColumn('b_user_group', 'GROUP_ID');
        $result = $this->context->connection->query(
            "SELECT DISTINCT {$userId} AS USER_ID FROM {$table} "
            . "WHERE {$groupId} = 1 ORDER BY {$userId}"
        );

        $ids = [];
        while ($row = $result->fetch()) {
            $id = (int)($row['USER_ID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function administratorGroupLookupAvailable(): bool
    {
        return $this->schema->hasTable('b_user_group')
            && $this->schema->hasColumn('b_user_group', 'USER_ID')
            && $this->schema->hasColumn('b_user_group', 'GROUP_ID');
    }

    /** @return list<string> */
    private function resolveTables(array $definition): array
    {
        if (isset($definition['table'])) {
            $table = (string)$definition['table'];
            return $this->schema->hasTable($table) ? [$table] : [];
        }
        if (isset($definition['table_pattern'])) {
            return $this->schema->tablesMatching((string)$definition['table_pattern']);
        }
        return [];
    }

    /** @return array<string, mixed>|null */
    private function resolveTableOperation(array $definition, string $blockId, string $table): ?array
    {
        $where = (array)($definition['where'] ?? []);
        foreach ($where as $condition) {
            if (!$this->schema->hasColumn($table, (string)($condition['column'] ?? ''))) {
                return null;
            }
        }

        $base = [
            'block' => $blockId,
            'type' => $definition['type'],
            'table' => $table,
            'stage' => (int)($definition['stage'] ?? 20),
            'where' => $where,
            'primary_key' => $this->schema->singlePrimaryKey($table),
        ];

        if ($definition['type'] === 'delete') {
            $base['estimated_rows'] = $this->schema->countRows($table, $where);
            return $base;
        }

        if ($definition['type'] === 'collect_files') {
            $columns = $this->resolveColumns(
                $table,
                (array)($definition['columns'] ?? []),
                (array)($definition['column_patterns'] ?? []),
                []
            );
            if ($columns === []) {
                return null;
            }
            $base['columns'] = $columns;
            $base['estimated_rows'] = $this->schema->countRows($table, $where);
            return $base;
        }

        if ($definition['type'] !== 'update') {
            return null;
        }

        $set = [];
        foreach ((array)($definition['set'] ?? []) as $column => $specification) {
            if ($this->schema->hasColumn($table, (string)$column)) {
                $set[(string)$column] = $specification;
            }
        }
        $emptyColumns = $this->resolveColumns(
            $table,
            (array)($definition['empty'] ?? []),
            (array)($definition['column_patterns'] ?? []),
            (array)($definition['exclude_patterns'] ?? []),
            (array)($definition['allowed_data_types'] ?? [])
        );
        $columnSpecification = is_array($definition['column_specification'] ?? null)
            ? $definition['column_specification']
            : ['mode' => 'empty'];
        foreach ($emptyColumns as $column) {
            if (isset($set[$column])) {
                continue;
            }
            if (($columnSpecification['mode'] ?? 'empty') === 'empty'
                && $this->schema->neutralExpression($table, $column) === null) {
                continue;
            }
            $set[$column] = $columnSpecification;
        }
        if ($set === []) {
            return null;
        }

        $base['set'] = $set;
        $base['estimated_rows'] = $this->schema->countRows($table, $where);
        return $base;
    }

    /**
     * @param list<string> $explicit
     * @param list<string> $patterns
     * @param list<string> $excludePatterns
     * @param list<string> $allowedDataTypes
     * @return list<string>
     */
    private function resolveColumns(
        string $table,
        array $explicit,
        array $patterns,
        array $excludePatterns,
        array $allowedDataTypes = [],
    ): array {
        $allowedDataTypes = array_map('strtolower', $allowedDataTypes);
        $result = [];
        foreach ($explicit as $column) {
            if ($this->schema->hasColumn($table, $column)
                && $this->columnHasAllowedDataType($table, $column, $allowedDataTypes)) {
                $result[$column] = true;
            }
        }
        foreach (array_keys($this->schema->columns($table)) as $column) {
            $matched = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $column) === 1) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            if ($column === 'ID' || (str_ends_with($column, '_ID') && !in_array($column, $explicit, true))) {
                continue;
            }
            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $column) === 1) {
                    continue 2;
                }
            }
            if (!$this->columnHasAllowedDataType($table, $column, $allowedDataTypes)) {
                continue;
            }
            $result[$column] = true;
        }

        return array_keys($result);
    }

    /** @param list<string> $allowedDataTypes */
    private function columnHasAllowedDataType(string $table, string $column, array $allowedDataTypes): bool
    {
        if ($allowedDataTypes === []) {
            return true;
        }
        $dataType = strtolower((string)($this->schema->column($table, $column)['DATA_TYPE'] ?? ''));
        return in_array($dataType, $allowedDataTypes, true);
    }

    /**
     * @param list<string> $selectedBlocks
     * @return array<string, mixed>|null
     */
    private function resolveSpecial(array $definition, string $blockId, string $runId, array $selectedBlocks): ?array
    {
        $name = (string)$definition['name'];
        $operation = [
            'block' => $blockId,
            'type' => 'special',
            'name' => $name,
            'stage' => (int)$definition['stage'],
            'estimated_rows' => null,
        ];

        if ($name === 'crm_email_activities') {
            if (!$this->schema->hasTable('b_crm_act') || !$this->schema->hasColumn('b_crm_act', 'TYPE_ID')) {
                return null;
            }
            $operation['estimated_rows'] = $this->schema->countRows('b_crm_act', [
                ['column' => 'TYPE_ID', 'op' => 'eq', 'value' => 4],
            ]);
            $operation['collect_files'] = in_array('files_disk', $selectedBlocks, true);
            return $operation;
        }

        if ($name === 'sanitize_crm_location_addresses') {
            $sources = $this->crmLocationAddressSources();
            $hasLinks = $this->crmLocationAddressLinksAvailable();
            if ($sources === [] && !$hasLinks) {
                return null;
            }

            $estimatedRows = array_sum(array_column($sources, 'estimated_rows'));
            if ($hasLinks) {
                $estimatedRows += $this->countCrmLocationAddressLinks();
            }
            $operation['sources'] = $sources;
            $operation['sanitize_linked_addresses'] = $hasLinks;
            $operation['estimated_rows'] = $estimatedRows;
            return $operation;
        }

        if ($name === 'user_fields') {
            $planner = new UserFieldPlanner($this->context, $this->schema, $this->store, $runId);
            $decisions = $planner->createDecisions();
            $summary = (array)($decisions['summary'] ?? []);
            $operation['estimated_rows'] = count((array)($decisions['fields'] ?? []));
            $operation['uf_summary'] = $summary;
            $operation['collect_files'] = in_array('files_disk', $selectedBlocks, true);
            return $operation;
        }

        if ($name === 'sanitize_process_iblocks') {
            if (!$this->schema->hasTable('b_iblock')
                || !$this->schema->hasTable('b_iblock_element')
                || !$this->schema->hasColumn('b_iblock', 'ID')
                || !$this->schema->hasColumn('b_iblock', 'IBLOCK_TYPE_ID')
                || !$this->schema->hasColumn('b_iblock_element', 'IBLOCK_ID')) {
                return null;
            }
            $operation['estimated_rows'] = (int)$this->context->connection->queryScalar(
                'SELECT COUNT(*) FROM ' . $this->schema->quoteTable('b_iblock_element') . ' e'
                . ' INNER JOIN ' . $this->schema->quoteTable('b_iblock') . ' i'
                . ' ON i.' . $this->schema->quoteColumn('b_iblock', 'ID')
                . ' = e.' . $this->schema->quoteColumn('b_iblock_element', 'IBLOCK_ID')
                . " WHERE i." . $this->schema->quoteColumn('b_iblock', 'IBLOCK_TYPE_ID')
                . " IN ('bitrix_processes','lists','lists_socnet')"
            );
            $operation['collect_files'] = in_array('files_disk', $selectedBlocks, true);
            return $operation;
        }

        if ($name === 'sanitize_iblock_embedded_contacts') {
            if (!$this->schema->hasTable('b_iblock_element')) {
                return null;
            }
            $columns = array_values(array_filter(
                ['PREVIEW_TEXT', 'DETAIL_TEXT', 'SEARCHABLE_CONTENT'],
                fn(string $column): bool => $this->schema->hasColumn('b_iblock_element', $column)
            ));
            if ($columns === []) {
                return null;
            }
            $email = $this->schema->literal('[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}');
            $phone = $this->schema->literal('(\\+7|8)[ ()-]*[0-9]{3}[ ()-]*[0-9]{3}[ -]*[0-9]{2}[ -]*[0-9]{2}');
            $conditions = [];
            foreach ($columns as $column) {
                $quoted = $this->schema->quoteColumn('b_iblock_element', $column);
                $conditions[] = "{$quoted} REGEXP {$email} OR {$quoted} REGEXP {$phone}";
            }
            $operation['estimated_rows'] = (int)$this->context->connection->queryScalar(
                'SELECT COUNT(*) FROM ' . $this->schema->quoteTable('b_iblock_element')
                . ' WHERE (' . implode(') OR (', $conditions) . ')'
            );
            $operation['columns'] = $columns;
            return $operation;
        }

        if (in_array($name, ['sanitize_crm_activity_structures', 'sanitize_crm_timeline_structures'], true)) {
            $definitions = $name === 'sanitize_crm_activity_structures'
                ? [
                    ['table' => 'b_crm_act', 'columns' => [
                        'SETTINGS' => 'serialized',
                        'PROVIDER_PARAMS' => 'serialized',
                        'PROVIDER_DATA' => 'encoded',
                    ]],
                ]
                : [
                    ['table' => 'b_crm_timeline', 'columns' => ['SETTINGS' => 'serialized_required']],
                    ['table' => 'b_crm_timeline_rest_app_layout_blocks', 'columns' => ['LAYOUT' => 'encoded']],
                ];

            $targets = [];
            foreach ($definitions as $target) {
                $table = (string)$target['table'];
                if (!$this->schema->hasTable($table)) {
                    continue;
                }
                $primaryKey = $this->schema->singlePrimaryKey($table);
                if ($primaryKey === null) {
                    continue;
                }
                $columns = [];
                foreach ((array)$target['columns'] as $column => $mode) {
                    if ($this->schema->hasColumn($table, (string)$column)) {
                        $columns[(string)$column] = (string)$mode;
                    }
                }
                if ($columns === []) {
                    continue;
                }
                $targets[] = [
                    'table' => $table,
                    'primary_key' => $primaryKey,
                    'columns' => $columns,
                    'estimated_rows' => $this->schema->countRows($table),
                ];
            }
            if ($targets === []) {
                return null;
            }
            $operation['targets'] = $targets;
            $operation['estimated_rows'] = array_sum(array_column($targets, 'estimated_rows'));
            return $operation;
        }

        if ($name === 'delete_files' && !$this->schema->hasTable('b_file')) {
            return null;
        }
        if ($name === 'sanitize_options' && !$this->schema->hasTable('b_option')) {
            return null;
        }

        return $operation;
    }

    /** @return list<array{table:string,primary_key:string,column:string,estimated_rows:int}> */
    private function crmLocationAddressSources(): array
    {
        $sources = [];
        foreach ($this->schema->tablesMatching('/^b_crm_/') as $table) {
            $primaryKey = $this->schema->singlePrimaryKey($table);
            if ($primaryKey === null) {
                continue;
            }
            foreach (array_keys($this->schema->columns($table)) as $column) {
                if (preg_match('/(^|_)LOC_ADDR_ID$/i', $column) !== 1) {
                    continue;
                }
                $sources[] = [
                    'table' => $table,
                    'primary_key' => $primaryKey,
                    'column' => $column,
                    'estimated_rows' => $this->schema->countRows($table, [
                        ['column' => $column, 'op' => 'ne', 'value' => 0],
                    ]),
                ];
            }
        }
        return $sources;
    }

    private function crmLocationAddressLinksAvailable(): bool
    {
        return $this->schema->hasTable('b_location_addr_link')
            && $this->schema->hasColumn('b_location_addr_link', 'ADDRESS_ID')
            && $this->schema->hasColumn('b_location_addr_link', 'ENTITY_TYPE');
    }

    private function countCrmLocationAddressLinks(): int
    {
        return (int)$this->context->connection->queryScalar(
            'SELECT COUNT(*) FROM ' . $this->schema->quoteTable('b_location_addr_link')
            . ' WHERE ' . $this->schema->quoteColumn('b_location_addr_link', 'ENTITY_TYPE')
            . ' REGEXP ' . $this->schema->literal('^CRM(_|$)')
        );
    }
}
