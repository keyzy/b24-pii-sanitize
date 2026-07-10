#!/usr/bin/env php
<?php

declare(strict_types=1);

use Keyzy\Pii\BitrixBootstrap;
use Keyzy\Pii\Options;
use Keyzy\Pii\SchemaInspector;
use Keyzy\Pii\StandaloneMysqlBootstrap;
use Keyzy\Pii\UserFieldAuditor;

$baseDir = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Keyzy\\Pii\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $baseDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

try {
    $options = Options::parse($argv);
    $decisions = $options->string('decisions', '') ?? '';
    $outputDir = $options->string('output-dir', $baseDir . '/var/uf-audit') ?? ($baseDir . '/var/uf-audit');
    if ($decisions === '') {
        throw new RuntimeException('Нужен --decisions.');
    }

    $root = $options->string('root');
    if ($root !== null && $root !== '') {
        $context = BitrixBootstrap::load($root);
    } else {
        $host = $options->string('db-host', 'localhost') ?? 'localhost';
        $port = $options->integer('db-port', 3306);
        $user = $options->string('db-user', '') ?? '';
        $database = $options->string('db-name', '') ?? '';
        $password = $options->string('db-password');
        if ($password === null) {
            $environmentPassword = getenv('PII_MYSQL_PASSWORD');
            $password = $environmentPassword === false ? null : $environmentPassword;
        }
        if ($user === '' || $database === '' || $password === null) {
            throw new RuntimeException('Нужны --root либо --db-user, --db-name и PII_MYSQL_PASSWORD.');
        }
        $context = StandaloneMysqlBootstrap::load($host, $port, $user, $password, $database);
    }

    $summary = (new UserFieldAuditor($context, new SchemaInspector($context)))->run($decisions, $outputDir);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($summary['status'] === 'passed' ? 0 : 2);
} catch (Throwable $exception) {
    fwrite(STDERR, '[ОШИБКА] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
