<?php

declare(strict_types=1);

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

use Keyzy\Pii\BlockCatalog;
use Keyzy\Pii\CrmStructuredPayloadSanitizer;
use Keyzy\Pii\FileIdExtractor;
use Keyzy\Pii\Options;
use Keyzy\Pii\SchemaInspector;
use Keyzy\Pii\StateStore;
use Keyzy\Pii\UserFieldPlanner;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$options = Options::parse([
    'pii-sanitize.php', '--root=/srv/site', '--apply', '--batch-size', '250',
    '--non-admin-login-mode=keep',
]);
check($options->string('root') === '/srv/site', 'Не разобран --root.');
check($options->has('apply'), 'Не разобран --apply.');
check($options->integer('batch-size', 1) === 250, 'Не разобран --batch-size.');
check($options->string('non-admin-login-mode') === 'keep', 'Не разобран режим логинов.');

$planner = (new ReflectionClass(UserFieldPlanner::class))->newInstanceWithoutConstructor();
$proposeAction = new ReflectionMethod(UserFieldPlanner::class, 'proposeAction');
$proposeAction->setAccessible(true);
[$companyNameAction] = $proposeAction->invoke($planner, [
    'ENTITY_ID' => 'CRM_QUOTE',
    'FIELD_NAME' => 'UF_CRM_QUOTE_COMPANY_NAME',
    'USER_TYPE_ID' => 'string',
], ['Наименование компании']);
check($companyNameAction === 'clear', 'Название компании в UF должно предлагаться к очистке.');

$neutralExpression = new ReflectionMethod(SchemaInspector::class, 'neutralExpressionForMetadata');
$neutralExpression->setAccessible(true);
check(
    $neutralExpression->invoke(null, ['DATA_TYPE' => 'text', 'IS_NULLABLE' => 'YES']) === "''",
    'Nullable-текст должен очищаться пустой строкой, а не NULL.'
);
check(
    $neutralExpression->invoke(null, ['DATA_TYPE' => 'decimal', 'IS_NULLABLE' => 'YES']) === 'NULL',
    'Nullable-число должно сохранять прежнюю политику NULL.'
);

$structuredPayload = [
    'TYPE' => 'layout',
    'ACTION' => 'open',
    'TASK_ID' => '42',
    'TITLE' => 'Иван Иванов',
    'START' => 'Старое значение',
    'AMOUNT' => 1500,
    'HAS_FILES' => 'Y',
    'TASK_FILE_IDS' => [10, 11],
    'RECIPIENT' => ['ID' => 'user@example.org', 'TYPEID' => 'CONTACT'],
    'WORKFLOW_AUTHOR' => ['ID' => 8, 'FULLNAME' => 'Иван Иванов', 'LINK' => '/company/personal/user/8/'],
];
$sanitizedPayload = CrmStructuredPayloadSanitizer::sanitizeValue($structuredPayload);
check($sanitizedPayload['TYPE'] === 'layout' && $sanitizedPayload['ACTION'] === 'open', 'Технические типы и действия должны сохраняться.');
check($sanitizedPayload['TASK_ID'] === '42', 'Технические ID в структурных данных должны сохраняться.');
check($sanitizedPayload['TITLE'] === 'Обезличено', 'Отображаемые названия должны обезличиваться.');
check($sanitizedPayload['START'] === '' && $sanitizedPayload['AMOUNT'] === 0, 'Старые значения и суммы должны очищаться.');
check($sanitizedPayload['HAS_FILES'] === 'N' && $sanitizedPayload['TASK_FILE_IDS'] === [], 'Файловые ссылки в структурных данных должны очищаться.');
check($sanitizedPayload['RECIPIENT']['ID'] === '', 'Получатель в структурных данных должен очищаться.');
check($sanitizedPayload['RECIPIENT']['TYPEID'] === 'CONTACT', 'Технический тип получателя должен сохраняться.');
check($sanitizedPayload['WORKFLOW_AUTHOR']['FULLNAME'] === 'Обезличено', 'Имя автора процесса должно обезличиваться.');
check($sanitizedPayload['WORKFLOW_AUTHOR']['LINK'] === '', 'Ссылка профиля в структурных данных должна очищаться.');
$serializedPayload = serialize($structuredPayload);
$serializedOnce = CrmStructuredPayloadSanitizer::sanitizeSerializedArray($serializedPayload, true);
check(
    CrmStructuredPayloadSanitizer::sanitizeSerializedArray($serializedOnce, true) === $serializedOnce,
    'Очистка сериализованных данных должна быть идемпотентной.'
);
$jsonPayload = CrmStructuredPayloadSanitizer::sanitizeEncodedArray('{"TYPE":"text","TITLE":"Имя","ACTION":"show","VALUE":"mail@example.org"}');
$jsonDecoded = json_decode($jsonPayload, true);
check($jsonDecoded['TYPE'] === 'text' && $jsonDecoded['ACTION'] === 'show', 'Структура JSON должна сохраняться.');
check($jsonDecoded['TITLE'] === 'Обезличено' && $jsonDecoded['VALUE'] === '', 'Пользовательские строки JSON должны очищаться.');

check(FileIdExtractor::extract(42) === [42], 'Не извлечен числовой ID.');
check(FileIdExtractor::extract('[12,"13",0]') === [12, 13], 'Не извлечены JSON ID.');
check(FileIdExtractor::extract('a:2:{i:0;i:21;i:1;s:2:"22";}') === [21, 22], 'Не извлечены serialized ID.');
check(FileIdExtractor::extract('/upload/2026/report-123.pdf') === [], 'Путь ошибочно принят за ID файла.');

$clearCatalog = BlockCatalog::all('clear');
$deleteCatalog = BlockCatalog::all('delete');
$keepLoginCatalog = BlockCatalog::all('clear', 'keep');
check(count($clearCatalog) >= 14, 'В каталоге неожиданно мало блоков.');
$optionalBlocks = ['crm_financials' => true, 'catalog_prices' => true, 'marketing' => true];
foreach ($clearCatalog as $blockId => $block) {
    $expectedDefault = !isset($optionalBlocks[$blockId]);
    check($block['default'] === $expectedDefault, "Некорректное состояние блока по умолчанию: {$blockId}.");
}

foreach (['crm_financials', 'catalog_prices'] as $blockId) {
    check(isset($clearCatalog[$blockId]), "Не найден финансовый блок {$blockId}.");
    check($clearCatalog[$blockId]['operations'] !== [], "Финансовый блок {$blockId} пуст.");
    foreach ($clearCatalog[$blockId]['operations'] as $operation) {
        check(
            ($operation['column_specification']['mode'] ?? '') === 'literal'
                && ($operation['column_specification']['value'] ?? null) === 0,
            "Финансовый блок {$blockId} должен записывать literal 0."
        );
        check(($operation['allowed_data_types'] ?? []) !== [], "В {$blockId} нет ограничения на числовые типы.");
    }
}

$marketingDeletePattern = null;
foreach ($clearCatalog['marketing']['operations'] as $operation) {
    if (($operation['type'] ?? '') === 'delete' && isset($operation['table_pattern'])) {
        $marketingDeletePattern = $operation['table_pattern'] ?? null;
    }
}
check(is_string($marketingDeletePattern), 'В блоке Маркетинга не найден шаблон удаления рабочих данных.');
check(preg_match($marketingDeletePattern, 'b_sender_group') === 1, 'Сегменты Маркетинга не включены в очистку.');
foreach (['b_sender_agreement', 'b_sender_permission', 'b_sender_preset_template', 'b_sender_role'] as $table) {
    check(preg_match($marketingDeletePattern, $table) === 0, "Системная таблица Маркетинга {$table} не должна очищаться.");
}

$taskBlockId = null;
$calendarBlockId = null;
foreach ($clearCatalog as $blockId => $block) {
    foreach ($block['operations'] as $operation) {
        if (($operation['type'] ?? '') !== 'update') {
            continue;
        }
        if (($operation['table'] ?? '') === 'b_tasks') {
            $taskBlockId = $blockId;
        }
        if (($operation['table'] ?? '') === 'b_calendar_event') {
            $calendarBlockId = $blockId;
        }
    }
}
check(is_string($taskBlockId), 'В меню нет отдельного блока задач.');
check(is_string($calendarBlockId), 'В меню нет блока календаря.');
check($taskBlockId !== $calendarBlockId, 'Задачи должны отключаться отдельно от календаря и ленты.');

$crmHistorySpecials = [];
foreach ($clearCatalog['crm_history']['operations'] as $operation) {
    if (($operation['type'] ?? '') === 'special') {
        $crmHistorySpecials[] = $operation['name'] ?? '';
    }
}
check(in_array('sanitize_crm_activity_structures', $crmHistorySpecials, true), 'Структура CRM-активностей должна очищаться специальной операцией.');
check(in_array('sanitize_crm_timeline_structures', $crmHistorySpecials, true), 'Структура таймлайна должна очищаться специальной операцией.');

$clearDeletesChat = false;
$deleteDeletesChat = false;
foreach ($clearCatalog['chats']['operations'] as $operation) {
    $clearDeletesChat = $clearDeletesChat || (($operation['type'] ?? '') === 'delete' && ($operation['table'] ?? '') === 'b_im_chat');
}
foreach ($deleteCatalog['chats']['operations'] as $operation) {
    $deleteDeletesChat = $deleteDeletesChat || (($operation['type'] ?? '') === 'delete' && ($operation['table'] ?? '') === 'b_im_chat');
}
check(!$clearDeletesChat, 'clear не должен удалять b_im_chat.');
check($deleteDeletesChat, 'delete должен удалять b_im_chat.');

$hasCrmLocationSanitizer = false;
foreach ($clearCatalog['crm_requisites']['operations'] as $operation) {
    $hasCrmLocationSanitizer = $hasCrmLocationSanitizer
        || (($operation['type'] ?? '') === 'special'
            && ($operation['name'] ?? '') === 'sanitize_crm_location_addresses');
}
check($hasCrmLocationSanitizer, 'Блок CRM-адресов должен очищать связанное хранилище Location.');

$landingFileCollected = false;
$landingFileLinkDeleted = false;
foreach ($clearCatalog['files_disk']['operations'] as $operation) {
    $landingFileCollected = $landingFileCollected
        || (($operation['type'] ?? '') === 'collect_files' && ($operation['table'] ?? '') === 'b_landing_file');
    $landingFileLinkDeleted = $landingFileLinkDeleted
        || (($operation['type'] ?? '') === 'delete' && ($operation['table'] ?? '') === 'b_landing_file');
}
check($landingFileCollected && $landingFileLinkDeleted, 'Файлы Landing должны попадать в очередь удаления.');

$userLoginMode = null;
foreach ($clearCatalog['users']['operations'] as $operation) {
    if (($operation['type'] ?? '') === 'update' && ($operation['table'] ?? '') === 'b_user') {
        $userLoginMode = $operation['set']['LOGIN']['mode'] ?? null;
    }
}
check(
    $userLoginMode === 'run_login_except_administrators',
    'LOGIN администраторов должен сохраняться при обезличивании пользователей.'
);

$keepLoginSet = null;
foreach ($keepLoginCatalog['users']['operations'] as $operation) {
    if (($operation['type'] ?? '') === 'update' && ($operation['table'] ?? '') === 'b_user') {
        $keepLoginSet = $operation['set'] ?? null;
    }
}
check(is_array($keepLoginSet), 'Не найдена операция пользователей для режима keep.');
check(!array_key_exists('LOGIN', $keepLoginSet), 'Режим keep не должен включать LOGIN в UPDATE.');

$protectedTables = [
    'b_crm_contact', 'b_crm_company', 'b_crm_deal', 'b_user', 'b_tasks',
    'b_crm_timeline', 'b_crm_timeline_bind', 'b_crm_event', 'b_crm_event_relations',
];
foreach ($clearCatalog as $blockId => $block) {
    foreach ($block['operations'] as $operation) {
        if (($operation['type'] ?? '') !== 'delete') {
            continue;
        }
        foreach ($protectedTables as $table) {
            $matchesDirectly = ($operation['table'] ?? '') === $table;
            $pattern = $operation['table_pattern'] ?? null;
            $matchesPattern = is_string($pattern) && preg_match($pattern, $table) === 1;
            if ($matchesDirectly || $matchesPattern) {
                throw new RuntimeException("Блок {$blockId} физически удаляет защищенную сущность {$table}.");
            }
        }
    }
}

$temporary = sys_get_temp_dir() . '/keyzy_pii_test_' . bin2hex(random_bytes(4));
$store = new StateStore($temporary);
$runId = $store->createRun(['status' => 'test']);
$state = $store->loadState($runId);
check($state['status'] === 'test', 'Не восстановлено состояние запуска.');
$state['status'] = 'updated';
$store->saveState($runId, $state);
check($store->loadState($runId)['status'] === 'updated', 'Не обновлено существующее состояние запуска.');
check($store->latestRunId() === $runId, 'Не найден последний запуск.');
$store->saveArtifact($runId, 'sample.json', ['ok' => true]);
check(($store->loadArtifact($runId, 'sample.json')['ok'] ?? false) === true, 'Не восстановлен артефакт.');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($temporary);

fwrite(STDOUT, "OK: smoke tests passed\n");
