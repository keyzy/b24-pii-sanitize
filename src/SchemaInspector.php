<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class SchemaInspector
{
    /** @var array<string, true>|null */
    private ?array $tables = null;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $columns = [];

    /** @var array<string, array<string, true>> */
    private array $uniqueIndexedColumns = [];

    public function __construct(private readonly BitrixContext $context)
    {
    }

    /** @return list<string> */
    public function tables(): array
    {
        $this->loadTables();
        $tables = array_keys($this->tables ?? []);
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** @return list<string> */
    public function tablesMatching(string $pattern): array
    {
        $result = [];
        foreach ($this->tables() as $table) {
            if (preg_match($pattern, $table) === 1) {
                $result[] = $table;
            }
        }
        return $result;
    }

    public function hasTable(string $table): bool
    {
        $this->loadTables();
        return isset($this->tables[$table]);
    }

    /** @return array<string, array<string, mixed>> */
    public function columns(string $table): array
    {
        $this->assertTable($table);
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }

        $safeTable = $this->context->sqlHelper->forSql($table);
        $result = $this->context->connection->query(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA, ORDINAL_POSITION,
                    CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$safeTable}'
             ORDER BY ORDINAL_POSITION"
        );
        $columns = [];
        while ($row = $result->fetch()) {
            $columns[(string)$row['COLUMN_NAME']] = $row;
        }
        $this->columns[$table] = $columns;
        return $columns;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && isset($this->columns($table)[$column]);
    }

    /** @return array<string, mixed> */
    public function column(string $table, string $column): array
    {
        if (!$this->hasColumn($table, $column)) {
            throw new RuntimeException("Не найдена колонка {$table}.{$column}.");
        }
        return $this->columns($table)[$column];
    }

    public function singlePrimaryKey(string $table): ?string
    {
        $primary = [];
        foreach ($this->columns($table) as $name => $column) {
            if (($column['COLUMN_KEY'] ?? '') === 'PRI') {
                $primary[] = $name;
            }
        }
        return count($primary) === 1 ? $primary[0] : null;
    }

    /** @param list<array<string, mixed>> $where */
    public function countRows(string $table, array $where = []): int
    {
        $quotedTable = $this->quoteTable($table);
        $whereSql = $this->buildWhere($table, $where);
        return (int)$this->context->connection->queryScalar("SELECT COUNT(*) FROM {$quotedTable}{$whereSql}");
    }

    /** @param list<array<string, mixed>> $where */
    public function buildWhere(string $table, array $where, string $prefix = ' WHERE '): string
    {
        if ($where === []) {
            return '';
        }

        $parts = [];
        foreach ($where as $condition) {
            $column = (string)($condition['column'] ?? '');
            if (!$this->hasColumn($table, $column)) {
                throw new RuntimeException("Условие ссылается на отсутствующую колонку {$table}.{$column}.");
            }
            $quotedColumn = $this->quoteColumn($table, $column);
            $operator = strtolower((string)($condition['op'] ?? 'eq'));
            if ($operator === 'in') {
                $values = (array)($condition['values'] ?? []);
                if ($values === []) {
                    $parts[] = '1 = 0';
                } else {
                    $parts[] = $quotedColumn . ' IN (' . implode(', ', array_map([$this, 'literal'], $values)) . ')';
                }
            } elseif ($operator === 'not_in') {
                $values = (array)($condition['values'] ?? []);
                $parts[] = $values === []
                    ? '1 = 1'
                    : $quotedColumn . ' NOT IN (' . implode(', ', array_map([$this, 'literal'], $values)) . ')';
            } elseif ($operator === 'eq') {
                $parts[] = $quotedColumn . ' = ' . $this->literal($condition['value'] ?? null);
            } elseif ($operator === 'ne') {
                $parts[] = $quotedColumn . ' <> ' . $this->literal($condition['value'] ?? null);
            } elseif ($operator === 'gt') {
                $parts[] = $quotedColumn . ' > ' . $this->literal($condition['value'] ?? null);
            } elseif ($operator === 'is_not_null') {
                $parts[] = $quotedColumn . ' IS NOT NULL';
            } elseif ($operator === 'is_null') {
                $parts[] = $quotedColumn . ' IS NULL';
            } else {
                throw new RuntimeException("Неподдерживаемый оператор условия: {$operator}");
            }
        }

        return $prefix . implode(' AND ', $parts);
    }

    public function quoteTable(string $table): string
    {
        $this->assertTable($table);
        return $this->context->sqlHelper->quote($table);
    }

    public function quoteColumn(string $table, string $column): string
    {
        if (!$this->hasColumn($table, $column)) {
            throw new RuntimeException("Недопустимый идентификатор {$table}.{$column}.");
        }
        return $this->context->sqlHelper->quote($column);
    }

    public function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . $this->context->sqlHelper->forSql((string)$value) . "'";
    }

    public function neutralExpression(string $table, string $column): ?string
    {
        return self::neutralExpressionForMetadata($this->column($table, $column));
    }

    /** @param array<string,mixed> $metadata */
    private static function neutralExpressionForMetadata(array $metadata): ?string
    {
        $type = strtolower((string)($metadata['DATA_TYPE'] ?? ''));
        if (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'], true)) {
            return "''";
        }
        if (($metadata['IS_NULLABLE'] ?? 'NO') === 'YES') {
            return 'NULL';
        }
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit'], true)) {
            return '0';
        }
        if ($type === 'json') {
            return "'{}'";
        }

        return null;
    }

    public function isTextColumn(string $table, string $column): bool
    {
        $type = strtolower((string)($this->column($table, $column)['DATA_TYPE'] ?? ''));
        return in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true);
    }

    public function isPartOfUniqueIndex(string $table, string $column): bool
    {
        $this->assertTable($table);
        if (!isset($this->uniqueIndexedColumns[$table])) {
            $safeTable = $this->context->sqlHelper->forSql($table);
            $result = $this->context->connection->query(
                "SELECT DISTINCT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = '{$safeTable}'
                   AND NON_UNIQUE = 0
                   AND INDEX_NAME <> 'PRIMARY'"
            );
            $columns = [];
            while ($row = $result->fetch()) {
                $columns[(string)$row['COLUMN_NAME']] = true;
            }
            $this->uniqueIndexedColumns[$table] = $columns;
        }
        return isset($this->uniqueIndexedColumns[$table][$column]);
    }

    private function loadTables(): void
    {
        if ($this->tables !== null) {
            return;
        }
        $result = $this->context->connection->query(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        $tables = [];
        while ($row = $result->fetch()) {
            $tables[(string)$row['TABLE_NAME']] = true;
        }
        $this->tables = $tables;
    }

    private function assertTable(string $table): void
    {
        if (!$this->hasTable($table)) {
            throw new RuntimeException("Таблица не найдена: {$table}");
        }
    }
}
