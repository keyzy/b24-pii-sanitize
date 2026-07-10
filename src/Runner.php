<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class Runner
{
    private readonly FileQueue $fileQueue;
    private readonly string $runToken;

    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
        private readonly StateStore $store,
        private readonly Console $console,
        private readonly string $runId,
        private readonly int $batchSize,
    ) {
        $this->fileQueue = new FileQueue($context, $schema, $store, $runId);
        $this->runToken = strtolower(substr(hash('sha256', $runId), 0, 10));
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $plan */
    public function execute(array &$state, array $plan): void
    {
        $operations = (array)($plan['operations'] ?? []);
        $startIndex = (int)($state['operation_index'] ?? 0);
        $state['status'] = 'running';
        $state['phase'] = 'apply';
        $this->checkpoint($state);

        for ($index = $startIndex, $count = count($operations); $index < $count; $index++) {
            $operation = $operations[$index];
            if (!is_array($operation)) {
                throw new RuntimeException("Некорректная операция плана с индексом {$index}.");
            }

            $operationId = (string)($operation['id'] ?? "operation_{$index}");
            if (($state['current_operation_id'] ?? null) !== $operationId) {
                $state['current_operation_id'] = $operationId;
                $state['operation_state'] = [];
                $this->checkpoint($state);
            }

            $label = (string)($operation['table'] ?? $operation['name'] ?? $operationId);
            $this->console->info(sprintf('[%d/%d] %s', $index + 1, $count, $label));
            $this->store->appendJsonLine($this->runId, 'events.jsonl', [
                'event' => 'operation_started',
                'operation_id' => $operationId,
                'type' => $operation['type'] ?? null,
                'table' => $operation['table'] ?? null,
                'name' => $operation['name'] ?? null,
            ]);

            try {
                $progress =& $state['operation_state'];
                $this->executeOperation($operation, $progress, $state, $plan);
                unset($progress);

                $this->store->appendJsonLine($this->runId, 'events.jsonl', [
                    'event' => 'operation_completed',
                    'operation_id' => $operationId,
                    'processed' => $state['operation_state']['processed'] ?? null,
                ]);
                $state['operation_index'] = $index + 1;
                $state['current_operation_id'] = null;
                $state['operation_state'] = [];
                $this->checkpoint($state);
            } catch (\Throwable $exception) {
                $state['status'] = 'failed';
                $state['last_error'] = $exception->getMessage();
                $this->checkpoint($state);
                $this->store->appendJsonLine($this->runId, 'events.jsonl', [
                    'event' => 'operation_failed',
                    'operation_id' => $operationId,
                    'error' => $exception->getMessage(),
                ]);
                throw $exception;
            }
        }

        $state['status'] = 'completed';
        $state['phase'] = 'completed';
        $state['completed_at'] = date(DATE_ATOM);
        unset($state['last_error']);
        $this->checkpoint($state);
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $progress
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     */
    private function executeOperation(array $operation, array &$progress, array &$state, array $plan): void
    {
        $type = (string)($operation['type'] ?? '');
        if ($type === 'update') {
            $this->executeUpdate($operation, $progress, $state);
            return;
        }
        if ($type === 'delete') {
            $this->executeDelete($operation, $progress, $state);
            return;
        }
        if ($type === 'collect_files') {
            $this->executeCollectFiles($operation, $progress, $state);
            return;
        }
        if ($type !== 'special') {
            throw new RuntimeException("Неподдерживаемый тип операции: {$type}");
        }

        $name = (string)($operation['name'] ?? '');
        if ($name === 'crm_email_activities') {
            $this->executeCrmEmailActivities($operation, $progress, $state);
        } elseif ($name === 'sanitize_crm_location_addresses') {
            $this->executeCrmLocationAddresses($operation, $progress, $state);
        } elseif (in_array($name, ['sanitize_crm_activity_structures', 'sanitize_crm_timeline_structures'], true)) {
            $this->executeStructuredPayloadTargets($operation, $progress, $state);
        } elseif ($name === 'user_fields') {
            $this->executeUserFields($operation, $progress, $state);
        } elseif ($name === 'sanitize_process_iblocks') {
            $this->executeProcessIblocks($operation, $progress, $state);
        } elseif ($name === 'sanitize_iblock_embedded_contacts') {
            $this->executeIblockEmbeddedContacts($operation, $progress, $state);
        } elseif ($name === 'delete_files') {
            $this->executeDeleteFiles($progress, $state);
        } elseif ($name === 'sanitize_options') {
            $this->executeSanitizeOptions($progress, $state);
        } elseif ($name === 'clear_cache') {
            $this->executeClearCache($progress, $state);
        } elseif ($name === 'verify') {
            $verifier = new Verifier($this->context, $this->schema, $this->store, $this->console, $this->runId);
            $checkpoint = function (array &$currentState): void {
                $this->checkpoint($currentState);
            };
            $verifier->run($progress, $state, $plan, $checkpoint);
        } else {
            throw new RuntimeException("Неизвестная специальная операция: {$name}");
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeStructuredPayloadTargets(array $operation, array &$progress, array &$state): void
    {
        $targets = array_values((array)($operation['targets'] ?? []));
        $startIndex = (int)($progress['target_index'] ?? 0);

        for ($index = $startIndex, $count = count($targets); $index < $count; $index++) {
            $target = (array)$targets[$index];
            $table = (string)($target['table'] ?? '');
            $primaryKey = (string)($target['primary_key'] ?? '');
            $columns = (array)($target['columns'] ?? []);
            if ($table === '' || $primaryKey === '' || $columns === [] || !$this->schema->hasTable($table)) {
                $progress['target_index'] = $index + 1;
                $this->checkpoint($state);
                continue;
            }

            if (!isset($progress['targets'][$index]) || !is_array($progress['targets'][$index])) {
                $progress['targets'][$index] = [];
            }
            $targetProgress =& $progress['targets'][$index];
            $quotedTable = $this->schema->quoteTable($table);
            $quotedPrimary = $this->schema->quoteColumn($table, $primaryKey);
            $quotedColumns = [];
            foreach ($columns as $column => $mode) {
                if ($this->schema->hasColumn($table, (string)$column)) {
                    $quotedColumns[(string)$column] = $this->schema->quoteColumn($table, (string)$column);
                }
            }
            if ($quotedColumns === []) {
                $progress['target_index'] = $index + 1;
                unset($targetProgress);
                $this->checkpoint($state);
                continue;
            }

            while (true) {
                $where = '';
                if (array_key_exists('last_primary_key', $targetProgress)) {
                    $where = ' WHERE ' . $quotedPrimary . ' > '
                        . $this->schema->literal($targetProgress['last_primary_key']);
                }
                $result = $this->context->connection->query(
                    'SELECT ' . $quotedPrimary . ', ' . implode(', ', array_values($quotedColumns))
                    . " FROM {$quotedTable}{$where} ORDER BY {$quotedPrimary} ASC LIMIT {$this->batchSize}"
                );
                $rows = [];
                while ($row = $result->fetch()) {
                    $rows[] = $row;
                }
                if ($rows === []) {
                    break;
                }

                $casesByColumn = [];
                $changedIds = [];
                foreach ($rows as $row) {
                    $id = $row[$primaryKey];
                    $idLiteral = $this->schema->literal($id);
                    foreach ($quotedColumns as $column => $quotedColumn) {
                        $raw = (string)($row[$column] ?? '');
                        $mode = (string)($columns[$column] ?? 'encoded');
                        $sanitized = match ($mode) {
                            'serialized' => CrmStructuredPayloadSanitizer::sanitizeSerializedArray($raw),
                            'serialized_required' => CrmStructuredPayloadSanitizer::sanitizeSerializedArray($raw, true),
                            'encoded' => CrmStructuredPayloadSanitizer::sanitizeEncodedArray($raw),
                            default => throw new RuntimeException("Неизвестный режим структурного поля: {$mode}"),
                        };
                        if ($sanitized === $raw) {
                            continue;
                        }
                        $casesByColumn[$column][] = 'WHEN ' . $idLiteral . ' THEN '
                            . $this->schema->literal($sanitized);
                        $changedIds[(string)$id] = $id;
                    }
                }

                $affected = 0;
                if ($casesByColumn !== []) {
                    $assignments = [];
                    foreach ($casesByColumn as $column => $cases) {
                        $quotedColumn = $quotedColumns[$column];
                        $assignments[] = $quotedColumn . ' = CASE ' . $quotedPrimary . ' '
                            . implode(' ', $cases) . ' ELSE ' . $quotedColumn . ' END';
                    }
                    $idSql = implode(', ', array_map([$this->schema, 'literal'], array_values($changedIds)));
                    $this->context->connection->startTransaction();
                    try {
                        $this->context->connection->queryExecute(
                            'UPDATE ' . $quotedTable . ' SET ' . implode(', ', $assignments)
                            . ' WHERE ' . $quotedPrimary . " IN ({$idSql})"
                        );
                        $affected = (int)$this->context->connection->getAffectedRowsCount();
                        $this->context->connection->commitTransaction();
                    } catch (\Throwable $exception) {
                        $this->context->connection->rollbackTransaction();
                        throw $exception;
                    }
                }

                $selected = count($rows);
                $targetProgress['last_primary_key'] = $rows[array_key_last($rows)][$primaryKey];
                $targetProgress['processed'] = (int)($targetProgress['processed'] ?? 0) + $selected;
                $targetProgress['affected'] = (int)($targetProgress['affected'] ?? 0) + $affected;
                $progress['processed'] = (int)($progress['processed'] ?? 0) + $selected;
                $progress['affected'] = (int)($progress['affected'] ?? 0) + $affected;
                $this->recordBatch($operation, $affected, $selected, ['table' => $table]);
                $this->checkpoint($state);
            }

            $progress['target_index'] = $index + 1;
            unset($targetProgress);
            $this->checkpoint($state);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeUpdate(array $operation, array &$progress, array &$state): void
    {
        $table = (string)$operation['table'];
        $primaryKey = is_string($operation['primary_key'] ?? null) ? $operation['primary_key'] : null;
        $setSql = $this->buildSetSql($table, (array)$operation['set'], $primaryKey);
        if ($setSql === '') {
            $progress['processed'] = (int)($progress['processed'] ?? 0);
            return;
        }

        $quotedTable = $this->schema->quoteTable($table);
        $baseWhere = (array)($operation['where'] ?? []);
        if ($primaryKey === null) {
            if (($progress['done'] ?? false) === true) {
                return;
            }
            $whereSql = $this->schema->buildWhere($table, $baseWhere);
            $this->context->connection->startTransaction();
            try {
                $this->context->connection->queryExecute("UPDATE {$quotedTable} SET {$setSql}{$whereSql}");
                $affected = (int)$this->context->connection->getAffectedRowsCount();
                $this->context->connection->commitTransaction();
            } catch (\Throwable $exception) {
                $this->context->connection->rollbackTransaction();
                throw $exception;
            }
            $progress['processed'] = (int)($progress['processed'] ?? 0) + $affected;
            $progress['done'] = true;
            $this->recordBatch($operation, $affected);
            $this->checkpoint($state);
            return;
        }

        while (true) {
            $conditions = $baseWhere;
            if (array_key_exists('last_primary_key', $progress)) {
                $conditions[] = ['column' => $primaryKey, 'op' => 'gt', 'value' => $progress['last_primary_key']];
            }
            $whereSql = $this->schema->buildWhere($table, $conditions);
            $quotedPrimary = $this->schema->quoteColumn($table, $primaryKey);
            $result = $this->context->connection->query(
                "SELECT {$quotedPrimary} FROM {$quotedTable}{$whereSql} ORDER BY {$quotedPrimary} ASC LIMIT {$this->batchSize}"
            );
            $ids = [];
            while ($row = $result->fetch()) {
                $ids[] = $row[$primaryKey];
            }
            if ($ids === []) {
                return;
            }

            $idSql = implode(', ', array_map([$this->schema, 'literal'], $ids));
            $this->context->connection->startTransaction();
            try {
                $this->context->connection->queryExecute(
                    "UPDATE {$quotedTable} SET {$setSql} WHERE {$quotedPrimary} IN ({$idSql})"
                );
                $affected = (int)$this->context->connection->getAffectedRowsCount();
                $this->context->connection->commitTransaction();
            } catch (\Throwable $exception) {
                $this->context->connection->rollbackTransaction();
                throw $exception;
            }

            $progress['last_primary_key'] = end($ids);
            $progress['processed'] = (int)($progress['processed'] ?? 0) + count($ids);
            $progress['affected'] = (int)($progress['affected'] ?? 0) + $affected;
            $this->recordBatch($operation, $affected, count($ids));
            $this->checkpoint($state);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeDelete(array $operation, array &$progress, array &$state): void
    {
        $table = (string)$operation['table'];
        $primaryKey = is_string($operation['primary_key'] ?? null) ? $operation['primary_key'] : null;
        $quotedTable = $this->schema->quoteTable($table);
        $where = (array)($operation['where'] ?? []);

        while (true) {
            if ($primaryKey !== null) {
                $quotedPrimary = $this->schema->quoteColumn($table, $primaryKey);
                $whereSql = $this->schema->buildWhere($table, $where);
                $result = $this->context->connection->query(
                    "SELECT {$quotedPrimary} FROM {$quotedTable}{$whereSql} ORDER BY {$quotedPrimary} ASC LIMIT {$this->batchSize}"
                );
                $ids = [];
                while ($row = $result->fetch()) {
                    $ids[] = $row[$primaryKey];
                }
                if ($ids === []) {
                    return;
                }
                $idSql = implode(', ', array_map([$this->schema, 'literal'], $ids));
                $deleteSql = "DELETE FROM {$quotedTable} WHERE {$quotedPrimary} IN ({$idSql})";
                $selected = count($ids);
            } else {
                $whereSql = $this->schema->buildWhere($table, $where);
                $deleteSql = "DELETE FROM {$quotedTable}{$whereSql} LIMIT {$this->batchSize}";
                $selected = $this->batchSize;
            }

            $this->context->connection->startTransaction();
            try {
                $this->context->connection->queryExecute($deleteSql);
                $affected = (int)$this->context->connection->getAffectedRowsCount();
                $this->context->connection->commitTransaction();
            } catch (\Throwable $exception) {
                $this->context->connection->rollbackTransaction();
                throw $exception;
            }
            if ($affected === 0) {
                return;
            }
            $progress['processed'] = (int)($progress['processed'] ?? 0) + $affected;
            $this->recordBatch($operation, $affected, $selected);
            $this->checkpoint($state);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeCollectFiles(array $operation, array &$progress, array &$state): void
    {
        $table = (string)$operation['table'];
        $primaryKey = is_string($operation['primary_key'] ?? null) ? $operation['primary_key'] : null;
        $columns = array_values((array)$operation['columns']);
        $quotedTable = $this->schema->quoteTable($table);
        $baseWhere = (array)($operation['where'] ?? []);

        while (true) {
            $selectColumns = $columns;
            $conditions = $baseWhere;
            if ($primaryKey !== null) {
                if (!in_array($primaryKey, $selectColumns, true)) {
                    array_unshift($selectColumns, $primaryKey);
                }
                if (array_key_exists('last_primary_key', $progress)) {
                    $conditions[] = ['column' => $primaryKey, 'op' => 'gt', 'value' => $progress['last_primary_key']];
                }
            }
            $quotedColumns = array_map(fn(string $column): string => $this->schema->quoteColumn($table, $column), $selectColumns);
            $whereSql = $this->schema->buildWhere($table, $conditions);
            $orderSql = $primaryKey === null ? '' : ' ORDER BY ' . $this->schema->quoteColumn($table, $primaryKey) . ' ASC';
            $offsetSql = $primaryKey === null ? ' OFFSET ' . (int)($progress['offset'] ?? 0) : '';
            $result = $this->context->connection->query(
                'SELECT ' . implode(', ', $quotedColumns) . " FROM {$quotedTable}{$whereSql}{$orderSql} LIMIT {$this->batchSize}{$offsetSql}"
            );

            $rows = [];
            while ($row = $result->fetch()) {
                $rows[] = $row;
            }
            if ($rows === []) {
                return;
            }

            $sourcesById = [];
            foreach ($rows as $row) {
                foreach ($columns as $column) {
                    foreach (FileIdExtractor::extract($row[$column] ?? null) as $fileId) {
                        $sourcesById[$fileId] = ['source_table' => $table, 'source_column' => $column];
                    }
                }
            }
            $queued = $this->fileQueue->enqueue($sourcesById);

            if ($primaryKey !== null) {
                $lastRow = end($rows);
                $progress['last_primary_key'] = $lastRow[$primaryKey];
            } else {
                $progress['offset'] = (int)($progress['offset'] ?? 0) + count($rows);
            }
            $progress['processed'] = (int)($progress['processed'] ?? 0) + count($rows);
            $progress['queued_files'] = (int)($progress['queued_files'] ?? 0) + $queued;
            $this->recordBatch($operation, $queued, count($rows), ['metric' => 'queued_files']);
            $this->checkpoint($state);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeCrmLocationAddresses(array $operation, array &$progress, array &$state): void
    {
        $sources = array_values(array_filter((array)($operation['sources'] ?? []), 'is_array'));
        $sourceIndex = (int)($progress['source_index'] ?? 0);

        for ($index = $sourceIndex, $count = count($sources); $index < $count; $index++) {
            $source = $sources[$index];
            $table = (string)($source['table'] ?? '');
            $primaryKey = (string)($source['primary_key'] ?? '');
            $locationColumn = (string)($source['column'] ?? '');
            if (!$this->schema->hasTable($table)
                || !$this->schema->hasColumn($table, $primaryKey)
                || !$this->schema->hasColumn($table, $locationColumn)) {
                throw new RuntimeException("Изменилась схема источника адресов {$table}.{$locationColumn}; создайте новый dry-run.");
            }

            $sourceKey = $table . '.' . $locationColumn;
            if (($progress['source_key'] ?? null) !== $sourceKey) {
                $progress['source_key'] = $sourceKey;
                unset($progress['last_primary_key']);
                $this->checkpoint($state);
            }

            $quotedTable = $this->schema->quoteTable($table);
            $quotedPrimary = $this->schema->quoteColumn($table, $primaryKey);
            $quotedLocation = $this->schema->quoteColumn($table, $locationColumn);
            $neutralLocation = $this->schema->neutralExpression($table, $locationColumn);
            if ($neutralLocation === null) {
                throw new RuntimeException("Не удалось подобрать безопасное значение для {$table}.{$locationColumn}.");
            }

            while (true) {
                $after = array_key_exists('last_primary_key', $progress)
                    ? ' AND ' . $quotedPrimary . ' > ' . $this->schema->literal($progress['last_primary_key'])
                    : '';
                $result = $this->context->connection->query(
                    "SELECT {$quotedPrimary} AS PII_PRIMARY_KEY, {$quotedLocation} AS PII_LOCATION_ID "
                    . "FROM {$quotedTable} WHERE {$quotedLocation} <> 0{$after} "
                    . "ORDER BY {$quotedPrimary} ASC LIMIT {$this->batchSize}"
                );
                $rows = [];
                while ($row = $result->fetch()) {
                    $rows[] = $row;
                }
                if ($rows === []) {
                    break;
                }

                $primaryValues = [];
                $locationIds = [];
                foreach ($rows as $row) {
                    $primaryValues[] = $row['PII_PRIMARY_KEY'];
                    $locationId = (int)($row['PII_LOCATION_ID'] ?? 0);
                    if ($locationId > 0) {
                        $locationIds[$locationId] = true;
                    }
                }
                $primarySql = implode(', ', array_map([$this->schema, 'literal'], $primaryValues));

                $this->context->connection->startTransaction();
                try {
                    $locationAffected = $this->sanitizeLocationAddressIds(array_keys($locationIds));
                    $this->context->connection->queryExecute(
                        "UPDATE {$quotedTable} SET {$quotedLocation} = {$neutralLocation} "
                        . "WHERE {$quotedPrimary} IN ({$primarySql})"
                    );
                    $sourceAffected = (int)$this->context->connection->getAffectedRowsCount();
                    $this->context->connection->commitTransaction();
                } catch (\Throwable $exception) {
                    $this->context->connection->rollbackTransaction();
                    throw $exception;
                }

                $lastRow = end($rows);
                $progress['last_primary_key'] = $lastRow['PII_PRIMARY_KEY'];
                $progress['processed'] = (int)($progress['processed'] ?? 0) + count($rows);
                $progress['location_ids_sanitized'] = (int)($progress['location_ids_sanitized'] ?? 0) + count($locationIds);
                $this->recordBatch($operation, $sourceAffected, count($rows), [
                    'source_table' => $table,
                    'source_column' => $locationColumn,
                    'location_rows_affected' => $locationAffected,
                ]);
                $this->checkpoint($state);
            }

            $progress['source_index'] = $index + 1;
            unset($progress['source_key'], $progress['last_primary_key']);
            $this->checkpoint($state);
        }

        if (($operation['sanitize_linked_addresses'] ?? false) === true) {
            $this->sanitizeLinkedCrmLocationAddresses($operation, $progress, $state);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function sanitizeLinkedCrmLocationAddresses(array $operation, array &$progress, array &$state): void
    {
        $table = 'b_location_addr_link';
        if (!$this->schema->hasTable($table)
            || !$this->schema->hasColumn($table, 'ADDRESS_ID')
            || !$this->schema->hasColumn($table, 'ENTITY_TYPE')) {
            return;
        }

        $quotedTable = $this->schema->quoteTable($table);
        $quotedAddress = $this->schema->quoteColumn($table, 'ADDRESS_ID');
        $quotedEntityType = $this->schema->quoteColumn($table, 'ENTITY_TYPE');
        $entityTypePattern = $this->schema->literal('^CRM(_|$)');

        while (true) {
            $lastAddressId = (int)($progress['linked_last_address_id'] ?? 0);
            $result = $this->context->connection->query(
                "SELECT DISTINCT {$quotedAddress} AS PII_LOCATION_ID FROM {$quotedTable} "
                . "WHERE {$quotedEntityType} REGEXP {$entityTypePattern} AND {$quotedAddress} > {$lastAddressId} "
                . "ORDER BY {$quotedAddress} ASC LIMIT {$this->batchSize}"
            );
            $locationIds = [];
            while ($row = $result->fetch()) {
                $locationId = (int)($row['PII_LOCATION_ID'] ?? 0);
                if ($locationId > 0) {
                    $locationIds[] = $locationId;
                }
            }
            if ($locationIds === []) {
                return;
            }

            $this->context->connection->startTransaction();
            try {
                $affected = $this->sanitizeLocationAddressIds($locationIds);
                $this->context->connection->commitTransaction();
            } catch (\Throwable $exception) {
                $this->context->connection->rollbackTransaction();
                throw $exception;
            }

            $progress['linked_last_address_id'] = end($locationIds);
            $progress['linked_addresses_processed'] = (int)($progress['linked_addresses_processed'] ?? 0) + count($locationIds);
            $this->recordBatch($operation, $affected, count($locationIds), [
                'source_table' => $table,
                'metric' => 'linked_location_addresses',
            ]);
            $this->checkpoint($state);
        }
    }

    /** @param list<int> $locationIds */
    private function sanitizeLocationAddressIds(array $locationIds): int
    {
        if ($locationIds === []) {
            return 0;
        }
        $idSql = implode(', ', array_map([$this->schema, 'literal'], $locationIds));
        $affected = 0;

        $fieldTable = 'b_location_addr_fld';
        if ($this->schema->hasTable($fieldTable) && $this->schema->hasColumn($fieldTable, 'ADDRESS_ID')) {
            $set = [];
            foreach (['VALUE', 'VALUE_NORMALIZED'] as $column) {
                if ($this->schema->hasColumn($fieldTable, $column)) {
                    $set[$column] = ['mode' => 'empty'];
                }
            }
            $setSql = $this->buildSetSql($fieldTable, $set, $this->schema->singlePrimaryKey($fieldTable));
            if ($setSql !== '') {
                $this->context->connection->queryExecute(
                    'UPDATE ' . $this->schema->quoteTable($fieldTable) . " SET {$setSql} WHERE "
                    . $this->schema->quoteColumn($fieldTable, 'ADDRESS_ID') . " IN ({$idSql})"
                );
                $affected += (int)$this->context->connection->getAffectedRowsCount();
            }
        }

        $addressTable = 'b_location_address';
        if ($this->schema->hasTable($addressTable) && $this->schema->hasColumn($addressTable, 'ID')) {
            $set = [];
            foreach (['LOCATION_ID', 'LATITUDE', 'LONGITUDE'] as $column) {
                if ($this->schema->hasColumn($addressTable, $column)) {
                    $set[$column] = ['mode' => 'empty'];
                }
            }
            $setSql = $this->buildSetSql($addressTable, $set, 'ID');
            if ($setSql !== '') {
                $this->context->connection->queryExecute(
                    'UPDATE ' . $this->schema->quoteTable($addressTable) . " SET {$setSql} WHERE "
                    . $this->schema->quoteColumn($addressTable, 'ID') . " IN ({$idSql})"
                );
                $affected += (int)$this->context->connection->getAffectedRowsCount();
            }
        }

        return $affected;
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeCrmEmailActivities(array $operation, array &$progress, array &$state): void
    {
        $table = 'b_crm_act';
        if (!$this->schema->hasTable($table) || !$this->schema->hasColumn($table, 'ID') || !$this->schema->hasColumn($table, 'TYPE_ID')) {
            return;
        }
        $quotedTable = $this->schema->quoteTable($table);
        $quotedId = $this->schema->quoteColumn($table, 'ID');
        $quotedType = $this->schema->quoteColumn($table, 'TYPE_ID');

        while (true) {
            $result = $this->context->connection->query(
                "SELECT {$quotedId} FROM {$quotedTable} WHERE {$quotedType} = 4 ORDER BY {$quotedId} ASC LIMIT {$this->batchSize}"
            );
            $ids = [];
            while ($row = $result->fetch()) {
                $ids[] = (int)$row['ID'];
            }
            if ($ids === []) {
                return;
            }
            $idSql = implode(', ', $ids);

            if (($operation['collect_files'] ?? false) === true
                && $this->schema->hasTable('b_crm_act_elem')
                && $this->schema->hasColumn('b_crm_act_elem', 'ACTIVITY_ID')
                && $this->schema->hasColumn('b_crm_act_elem', 'ELEMENT_ID')) {
                $elementTable = $this->schema->quoteTable('b_crm_act_elem');
                $activityColumn = $this->schema->quoteColumn('b_crm_act_elem', 'ACTIVITY_ID');
                $elementColumn = $this->schema->quoteColumn('b_crm_act_elem', 'ELEMENT_ID');
                $storageFilter = '';
                if ($this->schema->hasColumn('b_crm_act_elem', 'STORAGE_TYPE_ID')) {
                    $storageFilter = ' AND ' . $this->schema->quoteColumn('b_crm_act_elem', 'STORAGE_TYPE_ID') . ' = 1';
                }
                $fileResult = $this->context->connection->query(
                    "SELECT {$elementColumn} FROM {$elementTable} WHERE {$activityColumn} IN ({$idSql}){$storageFilter}"
                );
                $sources = [];
                while ($row = $fileResult->fetch()) {
                    foreach (FileIdExtractor::extract($row['ELEMENT_ID'] ?? null) as $fileId) {
                        $sources[$fileId] = ['source_table' => 'b_crm_act_elem', 'source_column' => 'ELEMENT_ID'];
                    }
                }
                $this->fileQueue->enqueue($sources);
            }

            $timelineIds = [];
            if ($this->schema->hasTable('b_crm_timeline')
                && $this->schema->hasColumn('b_crm_timeline', 'ID')
                && $this->schema->hasColumn('b_crm_timeline', 'TYPE_ID')
                && $this->schema->hasColumn('b_crm_timeline', 'ASSOCIATED_ENTITY_ID')) {
                $timelineTable = $this->schema->quoteTable('b_crm_timeline');
                $timelineId = $this->schema->quoteColumn('b_crm_timeline', 'ID');
                $timelineType = $this->schema->quoteColumn('b_crm_timeline', 'TYPE_ID');
                $associatedEntity = $this->schema->quoteColumn('b_crm_timeline', 'ASSOCIATED_ENTITY_ID');
                $timelineResult = $this->context->connection->query(
                    "SELECT {$timelineId} FROM {$timelineTable} "
                    . "WHERE {$timelineType} = 1 AND {$associatedEntity} IN ({$idSql})"
                );
                while ($row = $timelineResult->fetch()) {
                    $timelineIds[] = (int)$row['ID'];
                }
            }

            $this->context->connection->startTransaction();
            try {
                foreach (['b_crm_act_mail_meta', 'b_crm_act_comm', 'b_crm_act_bind', 'b_crm_act_elem', 'b_crm_act_relation'] as $dependent) {
                    if ($this->schema->hasTable($dependent) && $this->schema->hasColumn($dependent, 'ACTIVITY_ID')) {
                        $this->context->connection->queryExecute(
                            'DELETE FROM ' . $this->schema->quoteTable($dependent)
                            . ' WHERE ' . $this->schema->quoteColumn($dependent, 'ACTIVITY_ID') . " IN ({$idSql})"
                        );
                    }
                }
                if ($this->schema->hasTable('b_crm_timeline_note')
                    && $this->schema->hasColumn('b_crm_timeline_note', 'ITEM_ID')
                    && $this->schema->hasColumn('b_crm_timeline_note', 'ITEM_TYPE')) {
                    $this->context->connection->queryExecute(
                        'DELETE FROM ' . $this->schema->quoteTable('b_crm_timeline_note')
                        . ' WHERE ' . $this->schema->quoteColumn('b_crm_timeline_note', 'ITEM_ID')
                        . " IN ({$idSql}) AND " . $this->schema->quoteColumn('b_crm_timeline_note', 'ITEM_TYPE') . ' = 2'
                    );
                }
                if ($this->schema->hasTable('b_crm_timeline_rest_app_layout_blocks')
                    && $this->schema->hasColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_ID')
                    && $this->schema->hasColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_TYPE')) {
                    $this->context->connection->queryExecute(
                        'DELETE FROM ' . $this->schema->quoteTable('b_crm_timeline_rest_app_layout_blocks')
                        . ' WHERE ' . $this->schema->quoteColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_ID')
                        . " IN ({$idSql}) AND "
                        . $this->schema->quoteColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_TYPE') . ' = 1'
                    );
                }
                if ($timelineIds !== []) {
                    $timelineIdSql = implode(', ', $timelineIds);
                    foreach (['b_crm_timeline_bind', 'b_crm_timeline_search'] as $dependent) {
                        if ($this->schema->hasTable($dependent) && $this->schema->hasColumn($dependent, 'OWNER_ID')) {
                            $this->context->connection->queryExecute(
                                'DELETE FROM ' . $this->schema->quoteTable($dependent)
                                . ' WHERE ' . $this->schema->quoteColumn($dependent, 'OWNER_ID')
                                . " IN ({$timelineIdSql})"
                            );
                        }
                    }
                    if ($this->schema->hasTable('b_crm_timeline_note')
                        && $this->schema->hasColumn('b_crm_timeline_note', 'ITEM_ID')
                        && $this->schema->hasColumn('b_crm_timeline_note', 'ITEM_TYPE')) {
                        $this->context->connection->queryExecute(
                            'DELETE FROM ' . $this->schema->quoteTable('b_crm_timeline_note')
                            . ' WHERE ' . $this->schema->quoteColumn('b_crm_timeline_note', 'ITEM_ID')
                            . " IN ({$timelineIdSql}) AND "
                            . $this->schema->quoteColumn('b_crm_timeline_note', 'ITEM_TYPE') . ' = 1'
                        );
                    }
                    if ($this->schema->hasTable('b_crm_timeline_rest_app_layout_blocks')
                        && $this->schema->hasColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_ID')
                        && $this->schema->hasColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_TYPE')) {
                        $this->context->connection->queryExecute(
                            'DELETE FROM ' . $this->schema->quoteTable('b_crm_timeline_rest_app_layout_blocks')
                            . ' WHERE ' . $this->schema->quoteColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_ID')
                            . " IN ({$timelineIdSql}) AND "
                            . $this->schema->quoteColumn('b_crm_timeline_rest_app_layout_blocks', 'ITEM_TYPE') . ' = 2'
                        );
                    }
                    $this->context->connection->queryExecute(
                        'DELETE FROM ' . $this->schema->quoteTable('b_crm_timeline')
                        . ' WHERE ' . $this->schema->quoteColumn('b_crm_timeline', 'ID')
                        . " IN ({$timelineIdSql})"
                    );
                }
                $this->context->connection->queryExecute(
                    "DELETE FROM {$quotedTable} WHERE {$quotedId} IN ({$idSql})"
                );
                $affected = (int)$this->context->connection->getAffectedRowsCount();
                $this->context->connection->commitTransaction();
            } catch (\Throwable $exception) {
                $this->context->connection->rollbackTransaction();
                throw $exception;
            }

            $progress['processed'] = (int)($progress['processed'] ?? 0) + $affected;
            $this->recordBatch($operation, $affected, count($ids));
            $this->checkpoint($state);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeUserFields(array $operation, array &$progress, array &$state): void
    {
        $planner = new UserFieldPlanner($this->context, $this->schema, $this->store, $this->runId);
        $decisions = $planner->loadAndValidateDecisions();
        $tasks = $this->buildUserFieldTasks((array)$decisions['fields'], ($operation['collect_files'] ?? false) === true);
        $taskIndex = (int)($progress['task_index'] ?? 0);

        for ($index = $taskIndex, $count = count($tasks); $index < $count; $index++) {
            if (!isset($progress['task_progress']) || ($progress['task_id'] ?? null) !== $tasks[$index]['task_id']) {
                $progress['task_id'] = $tasks[$index]['task_id'];
                $progress['task_progress'] = [];
                $this->checkpoint($state);
            }
            $nested =& $progress['task_progress'];
            if ($tasks[$index]['type'] === 'update') {
                $this->executeUpdate($tasks[$index], $nested, $state);
            } elseif ($tasks[$index]['type'] === 'delete') {
                $this->executeDelete($tasks[$index], $nested, $state);
            } elseif ($tasks[$index]['type'] === 'collect_files') {
                $this->executeCollectFiles($tasks[$index], $nested, $state);
            }
            unset($nested);
            $progress['task_index'] = $index + 1;
            $progress['processed'] = $index + 1;
            unset($progress['task_id'], $progress['task_progress']);
            $this->checkpoint($state);
        }
    }

    /** @param list<array<string, mixed>> $fields @return list<array<string, mixed>> */
    private function buildUserFieldTasks(array $fields, bool $collectFiles): array
    {
        $collectTasks = [];
        $changeTasks = [];
        foreach ($fields as $field) {
            if (($field['action'] ?? '') !== 'clear') {
                continue;
            }
            $fieldId = (int)$field['id'];
            $fieldName = (string)$field['field_name'];
            $isFile = strtolower((string)($field['user_type_id'] ?? '')) === 'file';
            $isMultiple = (string)($field['multiple'] ?? 'N') === 'Y';

            if (!$isMultiple) {
                foreach ($this->schema->tablesMatching('/^b_(uts_|crm_dynamic_items_)/') as $table) {
                    if (!$this->schema->hasColumn($table, $fieldName)) {
                        continue;
                    }
                    if ($collectFiles && $isFile) {
                        $collectTasks[] = $this->runtimeOperation('collect_files', $table, [
                            'columns' => [$fieldName],
                            'where' => [],
                            'task_id' => "uf_collect_{$fieldId}_{$table}",
                        ]);
                    }
                    $changeTasks[] = $this->runtimeOperation('update', $table, [
                        'set' => [$fieldName => ['mode' => 'empty']],
                        'where' => [],
                        'task_id' => "uf_update_{$fieldId}_{$table}",
                    ]);
                }
                continue;
            }

            foreach ($this->schema->tablesMatching('/^b_utm_/') as $table) {
                if (!$this->schema->hasColumn($table, 'FIELD_ID')) {
                    continue;
                }
                $where = [['column' => 'FIELD_ID', 'op' => 'eq', 'value' => $fieldId]];
                if ($collectFiles && $isFile) {
                    $fileColumns = array_values(array_filter(['VALUE', 'VALUE_INT'], fn(string $column): bool => $this->schema->hasColumn($table, $column)));
                    if ($fileColumns !== []) {
                        $collectTasks[] = $this->runtimeOperation('collect_files', $table, [
                            'columns' => $fileColumns,
                            'where' => $where,
                            'task_id' => "uf_collect_{$fieldId}_{$table}",
                        ]);
                    }
                }
                $changeTasks[] = $this->runtimeOperation('delete', $table, [
                    'where' => $where,
                    'task_id' => "uf_delete_{$fieldId}_{$table}",
                ]);
            }
        }

        return array_merge($collectTasks, $changeTasks);
    }

    /** @param array<string,mixed> $operation @param array<string,mixed> $progress @param array<string,mixed> $state */
    private function executeProcessIblocks(array $operation, array &$progress, array &$state): void
    {
        $iblockResult = $this->context->connection->query(
            'SELECT ' . $this->schema->quoteColumn('b_iblock', 'ID')
            . ' FROM ' . $this->schema->quoteTable('b_iblock')
            . ' WHERE ' . $this->schema->quoteColumn('b_iblock', 'IBLOCK_TYPE_ID')
            . " IN ('bitrix_processes','lists','lists_socnet') ORDER BY "
            . $this->schema->quoteColumn('b_iblock', 'ID')
        );
        $iblockIds = [];
        while ($row = $iblockResult->fetch()) {
            $iblockIds[] = (int)$row['ID'];
        }
        if ($iblockIds === []) {
            return;
        }

        $elementResult = $this->context->connection->query(
            'SELECT ' . $this->schema->quoteColumn('b_iblock_element', 'ID')
            . ' FROM ' . $this->schema->quoteTable('b_iblock_element')
            . ' WHERE ' . $this->schema->quoteColumn('b_iblock_element', 'IBLOCK_ID')
            . ' IN (' . implode(', ', $iblockIds) . ')'
        );
        $elementIds = [];
        while ($row = $elementResult->fetch()) {
            $elementIds[] = (int)$row['ID'];
        }
        if ($elementIds === []) {
            return;
        }

        if (($operation['collect_files'] ?? false) === true && ($progress['files_collected'] ?? false) !== true) {
            $sources = [];
            $pictureColumns = array_values(array_filter(
                ['PREVIEW_PICTURE', 'DETAIL_PICTURE'],
                fn(string $column): bool => $this->schema->hasColumn('b_iblock_element', $column)
            ));
            if ($pictureColumns !== []) {
                $quoted = array_map(fn(string $column): string => $this->schema->quoteColumn('b_iblock_element', $column), $pictureColumns);
                $result = $this->context->connection->query(
                    'SELECT ' . implode(', ', $quoted) . ' FROM ' . $this->schema->quoteTable('b_iblock_element')
                    . ' WHERE ' . $this->schema->quoteColumn('b_iblock_element', 'ID')
                    . ' IN (' . implode(', ', $elementIds) . ')'
                );
                while ($row = $result->fetch()) {
                    foreach ($pictureColumns as $column) {
                        foreach (FileIdExtractor::extract($row[$column] ?? null) as $fileId) {
                            $sources[$fileId] = ['source_table' => 'b_iblock_element', 'source_column' => $column];
                        }
                    }
                }
            }
            if ($this->schema->hasTable('b_iblock_element_property')
                && $this->schema->hasTable('b_iblock_property')
                && $this->schema->hasColumn('b_iblock_element_property', 'IBLOCK_PROPERTY_ID')
                && $this->schema->hasColumn('b_iblock_element_property', 'IBLOCK_ELEMENT_ID')
                && $this->schema->hasColumn('b_iblock_element_property', 'VALUE')
                && $this->schema->hasColumn('b_iblock_property', 'PROPERTY_TYPE')) {
                $result = $this->context->connection->query(
                    'SELECT ep.' . $this->schema->quoteColumn('b_iblock_element_property', 'VALUE')
                    . ' FROM ' . $this->schema->quoteTable('b_iblock_element_property') . ' ep'
                    . ' INNER JOIN ' . $this->schema->quoteTable('b_iblock_property') . ' p'
                    . ' ON p.' . $this->schema->quoteColumn('b_iblock_property', 'ID')
                    . ' = ep.' . $this->schema->quoteColumn('b_iblock_element_property', 'IBLOCK_PROPERTY_ID')
                    . ' WHERE ep.' . $this->schema->quoteColumn('b_iblock_element_property', 'IBLOCK_ELEMENT_ID')
                    . ' IN (' . implode(', ', $elementIds) . ')'
                    . ' AND p.' . $this->schema->quoteColumn('b_iblock_property', 'PROPERTY_TYPE') . " = 'F'"
                );
                while ($row = $result->fetch()) {
                    foreach (FileIdExtractor::extract($row['VALUE'] ?? null) as $fileId) {
                        $sources[$fileId] = ['source_table' => 'b_iblock_element_property', 'source_column' => 'VALUE'];
                    }
                }
            }
            $this->fileQueue->enqueue($sources);
            $progress['files_collected'] = true;
            $this->checkpoint($state);
        }

        if (($progress['elements_updated'] ?? false) !== true) {
            $set = [
                'NAME' => ['mode' => 'concat_id', 'prefix' => 'Элемент '],
                'XML_ID' => ['mode' => 'concat_id', 'prefix' => 'pii_list_'],
            ];
            foreach (['CODE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'SEARCHABLE_CONTENT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE'] as $column) {
                if ($this->schema->hasColumn('b_iblock_element', $column)) {
                    $set[$column] = ['mode' => 'empty'];
                }
            }
            $runtime = $this->runtimeOperation('update', 'b_iblock_element', [
                'set' => $set,
                'where' => [['column' => 'IBLOCK_ID', 'op' => 'in', 'values' => $iblockIds]],
                'task_id' => 'process_iblock_elements',
            ]);
            if (!isset($progress['element_update_progress']) || !is_array($progress['element_update_progress'])) {
                $progress['element_update_progress'] = [];
            }
            $nested =& $progress['element_update_progress'];
            $this->executeUpdate($runtime, $nested, $state);
            unset($nested, $progress['element_update_progress']);
            $progress['elements_updated'] = true;
            $this->checkpoint($state);
        }

        if (($progress['properties_deleted'] ?? false) !== true
            && $this->schema->hasTable('b_iblock_element_property')
            && $this->schema->hasColumn('b_iblock_element_property', 'IBLOCK_ELEMENT_ID')) {
            $runtime = $this->runtimeOperation('delete', 'b_iblock_element_property', [
                'where' => [['column' => 'IBLOCK_ELEMENT_ID', 'op' => 'in', 'values' => $elementIds]],
                'task_id' => 'process_iblock_properties',
            ]);
            if (!isset($progress['property_delete_progress']) || !is_array($progress['property_delete_progress'])) {
                $progress['property_delete_progress'] = [];
            }
            $nested =& $progress['property_delete_progress'];
            $this->executeDelete($runtime, $nested, $state);
            unset($nested, $progress['property_delete_progress']);
            $progress['properties_deleted'] = true;
            $this->checkpoint($state);
        }
        $progress['processed'] = count($elementIds);
    }

    /** @param array<string,mixed> $operation @param array<string,mixed> $progress @param array<string,mixed> $state */
    private function executeIblockEmbeddedContacts(array $operation, array &$progress, array &$state): void
    {
        if (($progress['done'] ?? false) === true) {
            return;
        }
        $email = $this->schema->literal('[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}');
        $phone = $this->schema->literal('(\\+7|8)[ ()-]*[0-9]{3}[ ()-]*[0-9]{3}[ -]*[0-9]{2}[ -]*[0-9]{2}');
        $table = 'b_iblock_element';
        $affected = 0;
        $this->context->connection->startTransaction();
        try {
            foreach ((array)($operation['columns'] ?? []) as $column) {
                if (!$this->schema->hasColumn($table, (string)$column)) {
                    continue;
                }
                $quoted = $this->schema->quoteColumn($table, (string)$column);
                $empty = $this->schema->neutralExpression($table, (string)$column);
                if ($empty === null) {
                    continue;
                }
                $this->context->connection->queryExecute(
                    'UPDATE ' . $this->schema->quoteTable($table)
                    . " SET {$quoted} = {$empty}"
                    . " WHERE {$quoted} REGEXP {$email} OR {$quoted} REGEXP {$phone}"
                );
                $affected += (int)$this->context->connection->getAffectedRowsCount();
            }
            $this->context->connection->commitTransaction();
        } catch (\Throwable $exception) {
            $this->context->connection->rollbackTransaction();
            throw $exception;
        }
        $progress['processed'] = $affected;
        $progress['done'] = true;
        $this->checkpoint($state);
    }

    /** @return array<string, mixed> */
    private function runtimeOperation(string $type, string $table, array $extra): array
    {
        return $extra + [
            'id' => $extra['task_id'] ?? "runtime_{$type}_{$table}",
            'block' => 'custom_fields',
            'type' => $type,
            'table' => $table,
            'primary_key' => $this->schema->singlePrimaryKey($table),
        ];
    }

    /** @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeDeleteFiles(array &$progress, array &$state): void
    {
        $queuePath = $this->store->artifactPath($this->runId, 'file_queue.jsonl');
        if (!is_file($queuePath)) {
            $progress['processed'] = 0;
            return;
        }
        if ($this->context->runtimeMode === 'bitrix' && !class_exists('CFile')) {
            throw new RuntimeException('Класс CFile недоступен; физическое удаление файлов невозможно.');
        }

        $handle = fopen($queuePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть очередь файлов: {$queuePath}");
        }
        try {
            $offset = (int)($progress['offset'] ?? 0);
            if ($offset > 0) {
                fseek($handle, $offset);
            }
            while (!feof($handle)) {
                $ids = [];
                $read = 0;
                while ($read < $this->batchSize && ($line = fgets($handle)) !== false) {
                    $record = json_decode($line, true);
                    $fileId = is_array($record) ? (int)($record['file_id'] ?? 0) : 0;
                    if ($fileId > 0) {
                        $ids[$fileId] = true;
                    }
                    $read++;
                }
                $nextOffset = ftell($handle);
                if ($nextOffset === false) {
                    throw new RuntimeException('Не удалось определить позицию в очереди файлов.');
                }
                if ($read === 0) {
                    break;
                }

                $existingIds = [];
                if ($ids !== [] && $this->schema->hasTable('b_file') && $this->schema->hasColumn('b_file', 'ID')) {
                    $idSql = implode(', ', array_map('intval', array_keys($ids)));
                    $result = $this->context->connection->query(
                        'SELECT ' . $this->schema->quoteColumn('b_file', 'ID')
                        . ' FROM ' . $this->schema->quoteTable('b_file')
                        . ' WHERE ' . $this->schema->quoteColumn('b_file', 'ID') . " IN ({$idSql})"
                    );
                    while ($row = $result->fetch()) {
                        $existingIds[] = (int)$row['ID'];
                    }
                }

                $deleted = 0;
                if ($this->context->runtimeMode === 'bitrix') {
                    foreach ($existingIds as $fileId) {
                        \CFile::Delete($fileId);
                        $deleted++;
                    }
                } elseif ($existingIds !== []) {
                    $idSql = implode(', ', $existingIds);
                    $this->context->connection->startTransaction();
                    try {
                        $this->context->connection->queryExecute(
                            'DELETE FROM ' . $this->schema->quoteTable('b_file')
                            . ' WHERE ' . $this->schema->quoteColumn('b_file', 'ID') . " IN ({$idSql})"
                        );
                        $deleted = (int)$this->context->connection->getAffectedRowsCount();
                        $this->context->connection->commitTransaction();
                    } catch (\Throwable $exception) {
                        $this->context->connection->rollbackTransaction();
                        throw $exception;
                    }
                }

                $progress['offset'] = $nextOffset;
                $progress['processed'] = (int)($progress['processed'] ?? 0) + $read;
                $progress['deleted_files'] = (int)($progress['deleted_files'] ?? 0) + $deleted;
                $this->store->appendJsonLine($this->runId, 'events.jsonl', [
                    'event' => 'file_batch_deleted',
                    'queue_records' => $read,
                    'deleted_files' => $deleted,
                    'deletion_mode' => $this->context->runtimeMode === 'bitrix' ? 'cfile_and_upload' : 'b_file_only',
                ]);
                $this->checkpoint($state);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeSanitizeOptions(array &$progress, array &$state): void
    {
        if (($progress['done'] ?? false) === true) {
            return;
        }
        foreach (['MODULE_ID', 'NAME', 'VALUE'] as $column) {
            if (!$this->schema->hasColumn('b_option', $column)) {
                $progress['done'] = true;
                $this->checkpoint($state);
                return;
            }
        }

        $modules = ['mail', 'imconnector', 'imopenlines', 'voximplant', 'rest', 'oauth', 'sender', 'socialservices', 'pull'];
        $moduleSql = implode(', ', array_map([$this->schema, 'literal'], $modules));
        $table = $this->schema->quoteTable('b_option');
        $moduleColumn = $this->schema->quoteColumn('b_option', 'MODULE_ID');
        $nameColumn = $this->schema->quoteColumn('b_option', 'NAME');
        $valueColumn = $this->schema->quoteColumn('b_option', 'VALUE');
        $emailPattern = $this->schema->literal('[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}');
        $sql = "UPDATE {$table} SET {$valueColumn} = '' WHERE ("
            . "({$moduleColumn} IN ({$moduleSql})"
            . " AND {$nameColumn} REGEXP '(token|secret|password|passwd|key|appid|app_id|options_app|account_name|webhook|oauth|authorization|smtp|login|email)')"
            . " OR {$valueColumn} REGEXP {$emailPattern}"
            . " OR ({$moduleColumn} = 'main' AND {$nameColumn} IN ('signer_default_key','email_from'))"
            . ')';
        $this->context->connection->startTransaction();
        try {
            $this->context->connection->queryExecute($sql);
            $affected = (int)$this->context->connection->getAffectedRowsCount();
            $this->context->connection->commitTransaction();
        } catch (\Throwable $exception) {
            $this->context->connection->rollbackTransaction();
            throw $exception;
        }
        $progress['done'] = true;
        $progress['processed'] = $affected;
        $this->checkpoint($state);
    }

    /** @param array<string, mixed> $progress @param array<string, mixed> $state */
    private function executeClearCache(array &$progress, array &$state): void
    {
        if (($progress['done'] ?? false) === true) {
            return;
        }
        if (function_exists('BXClearCache')) {
            \BXClearCache(true);
        }
        if (isset($GLOBALS['CACHE_MANAGER']) && is_object($GLOBALS['CACHE_MANAGER']) && method_exists($GLOBALS['CACHE_MANAGER'], 'CleanAll')) {
            $GLOBALS['CACHE_MANAGER']->CleanAll();
        }
        $progress['done'] = true;
        $progress['processed'] = 1;
        $this->checkpoint($state);
    }

    /** @param array<string, mixed> $set */
    private function buildSetSql(string $table, array $set, ?string $primaryKey): string
    {
        $idColumn = $this->schema->hasColumn($table, 'ID') ? 'ID' : $primaryKey;
        $parts = [];
        foreach ($set as $column => $specification) {
            if (!$this->schema->hasColumn($table, (string)$column) || !is_array($specification)) {
                continue;
            }
            if (($this->schema->column($table, (string)$column)['COLUMN_KEY'] ?? '') === 'PRI') {
                continue;
            }
            $mode = (string)($specification['mode'] ?? 'empty');
            $expression = null;
            if ($mode === 'empty') {
                $expression = $this->emptyExpression($table, (string)$column, $idColumn);
            } elseif ($mode === 'literal') {
                $expression = $this->schema->literal($specification['value'] ?? null);
            } elseif ($idColumn !== null && $mode === 'concat_id') {
                $expression = 'CONCAT(' . $this->schema->literal((string)($specification['prefix'] ?? ''))
                    . ', CAST(' . $this->schema->quoteColumn($table, $idColumn) . ' AS CHAR))';
            } elseif ($idColumn !== null && in_array($mode, ['run_login', 'run_login_except_administrators', 'run_email', 'run_external_id'], true)) {
                $prefix = "pii_{$this->runToken}_user_";
                $suffix = $mode === 'run_email' ? '@example.invalid' : '';
                $replacement = 'CONCAT(' . $this->schema->literal($prefix)
                    . ', CAST(' . $this->schema->quoteColumn($table, $idColumn) . ' AS CHAR)'
                    . ($suffix === '' ? '' : ', ' . $this->schema->literal($suffix)) . ')';
                $expression = $mode === 'run_login_except_administrators'
                    ? $this->loginExceptAdministratorsExpression($table, (string)$column, $idColumn, $replacement)
                    : $replacement;
            }
            if ($expression !== null) {
                $parts[] = $this->schema->quoteColumn($table, (string)$column) . ' = ' . $expression;
            }
        }

        return implode(', ', $parts);
    }

    private function loginExceptAdministratorsExpression(
        string $table,
        string $column,
        string $idColumn,
        string $replacement,
    ): string {
        $currentLogin = $this->schema->quoteColumn($table, $column);
        if ($table !== 'b_user'
            || !$this->schema->hasTable('b_user_group')
            || !$this->schema->hasColumn('b_user_group', 'USER_ID')
            || !$this->schema->hasColumn('b_user_group', 'GROUP_ID')) {
            $this->console->warning(
                'Не удалось определить административную группу; LOGIN всех пользователей сохраняется.'
            );
            return $currentLogin;
        }

        $userGroup = $this->schema->quoteTable('b_user_group');
        $userId = $this->schema->quoteColumn('b_user_group', 'USER_ID');
        $groupId = $this->schema->quoteColumn('b_user_group', 'GROUP_ID');
        $currentId = $this->schema->quoteColumn($table, $idColumn);
        return "CASE WHEN {$currentId} IN ("
            . "SELECT {$userId} FROM {$userGroup} WHERE {$groupId} = 1"
            . ") THEN {$currentLogin} ELSE {$replacement} END";
    }

    private function emptyExpression(string $table, string $column, ?string $idColumn): ?string
    {
        if ($idColumn === null || !$this->schema->isPartOfUniqueIndex($table, $column)) {
            return $this->schema->neutralExpression($table, $column);
        }

        $metadata = $this->schema->column($table, $column);
        if (($metadata['IS_NULLABLE'] ?? 'NO') === 'YES') {
            return 'NULL';
        }
        $type = strtolower((string)($metadata['DATA_TYPE'] ?? ''));
        $quotedId = $this->schema->quoteColumn($table, $idColumn);
        if (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
            $prefix = '__pii_' . $this->runToken . '_';
            $maxLength = (int)($metadata['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            if ($maxLength > 0 && $maxLength < strlen($prefix) + 10) {
                return "RIGHT(CONCAT(REPEAT('0', {$maxLength}), CONV({$quotedId}, 10, 36)), {$maxLength})";
            }
            return 'CONCAT(' . $this->schema->literal($prefix) . ", CAST({$quotedId} AS CHAR))";
        }
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'], true)) {
            return '-1 * CAST(' . $quotedId . ' AS SIGNED)';
        }

        return $this->schema->neutralExpression($table, $column);
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $extra */
    private function recordBatch(array $operation, int $affected, ?int $selected = null, array $extra = []): void
    {
        $this->store->appendJsonLine($this->runId, 'events.jsonl', $extra + [
            'event' => 'batch_completed',
            'operation_id' => $operation['id'] ?? null,
            'table' => $operation['table'] ?? null,
            'selected_rows' => $selected,
            'affected_rows' => $affected,
        ]);
    }

    /** @param array<string, mixed> $state */
    private function checkpoint(array &$state): void
    {
        $this->store->saveState($this->runId, $state);
    }

}
