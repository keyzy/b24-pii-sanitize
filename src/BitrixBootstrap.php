<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class BitrixBootstrap
{
    public static function load(string $documentRoot): BitrixContext
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Скрипт разрешено запускать только из CLI.');
        }

        $realRoot = realpath($documentRoot);
        if ($realRoot === false) {
            throw new RuntimeException("Корень сайта не найден: {$documentRoot}");
        }
        $prolog = $realRoot . '/bitrix/modules/main/include/prolog_before.php';
        if (!is_file($prolog)) {
            throw new RuntimeException("Не найден загрузчик Bitrix: {$prolog}");
        }

        self::defineIfMissing('NO_KEEP_STATISTIC', true);
        self::defineIfMissing('NOT_CHECK_PERMISSIONS', true);
        self::defineIfMissing('BX_CRONTAB', true);
        self::defineIfMissing('NO_AGENT_CHECK', true);
        self::defineIfMissing('BX_NO_ACCELERATOR_RESET', true);
        self::defineIfMissing('DisableEventsCheck', true);

        $_SERVER['DOCUMENT_ROOT'] = $realRoot;
        $_SERVER['REQUEST_METHOD'] = 'CLI';
        $_SERVER['REQUEST_URI'] = '/local/tools/pii-sanitize';

        require_once $prolog;

        if (!class_exists(\Bitrix\Main\Application::class)) {
            throw new RuntimeException('D7 не загрузился после подключения prolog_before.php.');
        }

        $connection = \Bitrix\Main\Application::getConnection();
        $connectionClass = get_class($connection);
        if (!preg_match('/mysqli|mysql/i', $connectionClass)) {
            throw new RuntimeException("Поддерживается только MySQL/MariaDB; текущее соединение: {$connectionClass}");
        }

        $databaseName = (string)$connection->queryScalar('SELECT DATABASE()');
        if ($databaseName === '') {
            throw new RuntimeException('Не удалось определить имя текущей базы данных.');
        }

        try {
            $databaseHost = (string)$connection->queryScalar('SELECT @@hostname');
        } catch (\Throwable) {
            $databaseHost = 'unknown-host';
        }

        @set_time_limit(0);

        return new BitrixContext(
            $connection,
            $connection->getSqlHelper(),
            str_replace('\\', '/', $realRoot),
            $databaseName,
            $databaseHost,
            hash('sha256', $databaseHost . "\n" . $databaseName . "\n" . str_replace('\\', '/', $realRoot)),
            'bitrix',
        );
    }

    private static function defineIfMissing(string $name, mixed $value): void
    {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}
