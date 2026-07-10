<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use mysqli;
use RuntimeException;

final class StandaloneMysqlBootstrap
{
    public static function load(string $host, int $port, string $user, string $password, string $database): BitrixContext
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Скрипт разрешено запускать только из CLI.');
        }
        if (!extension_loaded('mysqli')) {
            throw new RuntimeException('Для standalone-режима требуется расширение mysqli.');
        }
        if ($port < 1 || $port > 65535 || $host === '' || $user === '' || $database === '') {
            throw new RuntimeException('Некорректные параметры standalone MySQL.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = mysqli_init();
        if ($mysqli === false) {
            throw new RuntimeException('Не удалось инициализировать mysqli.');
        }
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        $mysqli->real_connect($host, $user, $password, $database, $port);
        $mysqli->set_charset('utf8mb4');

        $connection = new StandaloneMysqlConnection($mysqli);
        $databaseName = (string)$connection->queryScalar('SELECT DATABASE()');
        if (!hash_equals($database, $databaseName)) {
            throw new RuntimeException("Подключена неожиданная база {$databaseName} вместо {$database}.");
        }
        $serverVersion = $connection->getVersion();
        if (version_compare(preg_replace('/[^0-9.].*$/', '', $serverVersion) ?: '0', '5.7.0', '<')) {
            throw new RuntimeException("Требуется MySQL 5.7+; сервер сообщает {$serverVersion}.");
        }

        return new BitrixContext(
            $connection,
            $connection->getSqlHelper(),
            '[standalone-mysql:no-upload]',
            $databaseName,
            $host . ':' . $port,
            hash('sha256', "standalone-mysql\n{$host}\n{$port}\n{$databaseName}"),
            'standalone-mysql',
        );
    }
}
