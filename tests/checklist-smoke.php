<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Console.php';

use Keyzy\Pii\Console;

$blocks = [
    'first' => ['title' => 'Первый', 'description' => 'Первый блок.', 'default' => true],
    'second' => ['title' => 'Второй', 'description' => 'Второй блок.', 'default' => false],
];

$selected = (new Console(true))->checklist($blocks);
if ($selected !== ['first', 'second']) {
    throw new RuntimeException('Команда a не выбрала все блоки.');
}

fwrite(STDOUT, 'CHECKLIST_RESULT=' . implode(',', $selected) . PHP_EOL);
