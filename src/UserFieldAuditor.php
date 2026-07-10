<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class UserFieldAuditor
{
    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(string $decisionsPath, string $outputDir): array
    {
        if (!is_file($decisionsPath)) {
            throw new RuntimeException("Не найден файл решений UF: {$decisionsPath}");
        }
        $decoded = json_decode((string)file_get_contents($decisionsPath), true, 512, JSON_THROW_ON_ERROR);
        $fields = is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];
        if (!is_dir($outputDir) && !mkdir($outputDir, 0770, true) && !is_dir($outputDir)) {
            throw new RuntimeException("Не удалось создать каталог {$outputDir}.");
        }

        $findings = [];
        $checkedStorageTargets = 0;
        $clearFields = 0;
        $fieldsWithoutStorage = 0;
        foreach ($fields as $field) {
            if (!is_array($field) || ($field['action'] ?? '') !== 'clear') {
                continue;
            }
            $clearFields++;
            $fieldId = (int)($field['id'] ?? 0);
            $fieldName = (string)($field['field_name'] ?? '');
            $multiple = (string)($field['multiple'] ?? 'N') === 'Y';
            $storageFound = false;

            if ($multiple) {
                foreach ($this->schema->tablesMatching('/^b_utm_/') as $table) {
                    if (!$this->schema->hasColumn($table, 'FIELD_ID')) {
                        continue;
                    }
                    $storageFound = true;
                    $checkedStorageTargets++;
                    $remaining = $this->schema->countRows($table, [
                        ['column' => 'FIELD_ID', 'op' => 'eq', 'value' => $fieldId],
                    ]);
                    if ($remaining > 0) {
                        $findings[] = $this->finding($field, $table, 'FIELD_ID', $remaining);
                    }
                }
            } else {
                foreach ($this->schema->tablesMatching('/^b_(uts_|crm_dynamic_items_)/') as $table) {
                    if (!$this->schema->hasColumn($table, $fieldName)) {
                        continue;
                    }
                    $storageFound = true;
                    $checkedStorageTargets++;
                    $column = $this->schema->column($table, $fieldName);
                    $quotedColumn = $this->schema->quoteColumn($table, $fieldName);
                    $condition = $this->remainingCondition($quotedColumn, $column);
                    $remaining = (int)$this->context->connection->queryScalar(
                        'SELECT COUNT(*) FROM ' . $this->schema->quoteTable($table) . ' WHERE ' . $condition
                    );
                    if ($remaining > 0) {
                        $findings[] = $this->finding($field, $table, $fieldName, $remaining);
                    }
                }
            }
            if (!$storageFound) {
                $fieldsWithoutStorage++;
            }
        }

        $summary = [
            'version' => 1,
            'database' => $this->context->databaseName,
            'completed_at' => date(DATE_ATOM),
            'clear_fields' => $clearFields,
            'checked_storage_targets' => $checkedStorageTargets,
            'fields_without_storage' => $fieldsWithoutStorage,
            'findings' => count($findings),
            'remaining_rows' => array_sum(array_column($findings, 'remaining_rows')),
            'contains_values' => false,
            'status' => $findings === [] ? 'passed' : 'warnings',
        ];
        file_put_contents(
            rtrim($outputDir, '/\\') . '/uf_audit_summary.json',
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            LOCK_EX
        );
        file_put_contents(
            rtrim($outputDir, '/\\') . '/uf_audit_findings.json',
            json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            LOCK_EX
        );
        return $summary;
    }

    /** @param array<string,mixed> $column */
    private function remainingCondition(string $quotedColumn, array $column): string
    {
        $type = strtolower((string)($column['DATA_TYPE'] ?? ''));
        if (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true)) {
            return 'COALESCE(' . $quotedColumn . ", '') <> ''";
        }
        if (($column['IS_NULLABLE'] ?? 'NO') === 'YES') {
            return $quotedColumn . ' IS NOT NULL';
        }
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit'], true)) {
            return $quotedColumn . ' <> 0';
        }
        if ($type === 'json') {
            return $quotedColumn . " <> '{}'";
        }
        return '1 = 1';
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private function finding(array $field, string $table, string $column, int $remaining): array
    {
        return [
            'field_id' => (int)($field['id'] ?? 0),
            'entity_id' => (string)($field['entity_id'] ?? ''),
            'field_name' => (string)($field['field_name'] ?? ''),
            'user_type_id' => (string)($field['user_type_id'] ?? ''),
            'table' => $table,
            'column' => $column,
            'remaining_rows' => $remaining,
            'contains_values' => false,
        ];
    }
}
