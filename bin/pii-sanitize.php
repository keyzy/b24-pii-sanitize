#!/usr/bin/env php
<?php

declare(strict_types=1);

use Keyzy\Pii\CliApplication;

$baseDir = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Keyzy\\Pii\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

try {
    exit((new CliApplication($baseDir))->run($argv));
} catch (Throwable $exception) {
    fwrite(STDERR, PHP_EOL . '[ОШИБКА] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
