<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Console.php';

use Keyzy\Pii\Console;

$console = new Console(true);
$console->requirePhrase('Проверка имени базы.', 'zcrm_2');

fwrite(STDOUT, 'CONFIRMATION_RESULT=passed' . PHP_EOL);
