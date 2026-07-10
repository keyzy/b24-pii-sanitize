<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use mysqli;
use mysqli_result;
use RuntimeException;

final class StandaloneMysqlConnection
{
    private int $affectedRows = 0;
    private readonly StandaloneMysqlSqlHelper $helper;

    public function __construct(private readonly mysqli $mysqli)
    {
        $this->helper = new StandaloneMysqlSqlHelper($mysqli);
    }

    public function getSqlHelper(): StandaloneMysqlSqlHelper
    {
        return $this->helper;
    }

    public function query(string $sql): StandaloneMysqlResult
    {
        $result = $this->mysqli->query($sql);
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('SELECT не вернул результирующий набор.');
        }
        return new StandaloneMysqlResult($result);
    }

    public function queryScalar(string $sql): mixed
    {
        $result = $this->query($sql);
        $row = $result->fetchNumeric();
        return $row[0] ?? null;
    }

    public function queryExecute(string $sql): void
    {
        $result = $this->mysqli->query($sql);
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $this->affectedRows = $this->mysqli->affected_rows;
    }

    public function getAffectedRowsCount(): int
    {
        return $this->affectedRows;
    }

    public function startTransaction(): void
    {
        $this->mysqli->begin_transaction();
    }

    public function commitTransaction(): void
    {
        $this->mysqli->commit();
    }

    public function rollbackTransaction(): void
    {
        $this->mysqli->rollback();
    }

    public function getVersion(): string
    {
        return $this->mysqli->server_info;
    }
}

final class StandaloneMysqlResult
{
    public function __construct(private readonly mysqli_result $result)
    {
    }

    /** @return array<string, mixed>|false */
    public function fetch(): array|false
    {
        return $this->result->fetch_assoc() ?? false;
    }

    /** @return array<int, mixed>|false */
    public function fetchNumeric(): array|false
    {
        return $this->result->fetch_row() ?? false;
    }
}

final class StandaloneMysqlSqlHelper
{
    public function __construct(private readonly mysqli $mysqli)
    {
    }

    public function forSql(string $value, int $maxLength = 0): string
    {
        if ($maxLength > 0) {
            $value = substr($value, 0, $maxLength);
        }
        return $this->mysqli->real_escape_string($value);
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
