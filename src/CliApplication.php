<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class CliApplication
{
    public function __construct(private readonly string $baseDir)
    {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $options = Options::parse($argv);
        $console = new Console(!$options->has('non-interactive'));
        if ($options->has('help')) {
            $this->printHelp($console);
            return 0;
        }

        $stateDir = $options->string('state-dir', $this->baseDir . '/var') ?? ($this->baseDir . '/var');
        $store = new StateStore($stateDir);
        if ($options->has('list-runs')) {
            $this->printRuns($store, $console);
            return 0;
        }
        if ($options->has('status')) {
            $runId = $options->string('status') ?: $store->latestRunId();
            if ($runId === null) {
                throw new RuntimeException('Нет сохраненных запусков.');
            }
            $this->printStatus($store, $console, $runId);
            return 0;
        }

        if ($options->has('resume')) {
            return $this->resume($options, $console, $store);
        }

        return $this->startNew($options, $console, $store);
    }

    private function startNew(Options $options, Console $console, StateStore $store): int
    {
        $chatMode = strtolower($options->string('chat-mode', 'clear') ?? 'clear');
        $this->assertChatMode($chatMode);
        $nonAdminLoginMode = strtolower($options->string('non-admin-login-mode', 'anonymize') ?? 'anonymize');
        $this->assertNonAdminLoginMode($nonAdminLoginMode);
        $catalog = BlockCatalog::all($chatMode, $nonAdminLoginMode);
        $blockMeta = [];
        foreach ($catalog as $id => $block) {
            $blockMeta[$id] = [
                'title' => $block['title'],
                'description' => $block['description'],
                'default' => $block['default'],
            ];
        }

        $selectedBlocks = $options->csv('blocks');
        if ($selectedBlocks === []) {
            $selectedBlocks = $console->checklist($blockMeta);
        }
        $this->assertSelectedBlocks($selectedBlocks, $catalog);

        if (in_array('chats', $selectedBlocks, true) && !$options->has('chat-mode') && $console->isInteractive()) {
            $chatMode = strtolower($console->prompt('Режим чатов: clear (сохранить чаты) или delete (удалить чаты)', 'clear'));
            $this->assertChatMode($chatMode);
        }
        if (in_array('users', $selectedBlocks, true)
            && !$options->has('non-admin-login-mode')
            && $console->isInteractive()) {
            $nonAdminLoginMode = strtolower($console->prompt(
                'Логины НЕ админов: anonymize (заменить) или keep (сохранить); логины админов всегда сохраняются',
                'anonymize'
            ));
            $this->assertNonAdminLoginMode($nonAdminLoginMode);
        }

        $context = $this->loadContext($options);
        $schema = new SchemaInspector($context);
        $batchSize = $this->validatedBatchSize($options->integer('batch-size', 500));

        $runId = $store->createRun([
            'version' => 1,
            'created_at' => date(DATE_ATOM),
            'status' => 'inventory',
            'phase' => 'inventory',
            'mode' => 'dry-run',
            'selected_blocks' => $selectedBlocks,
            'chat_mode' => $chatMode,
            'non_admin_login_mode' => $nonAdminLoginMode,
            'batch_size' => $batchSize,
            'database_fingerprint' => $context->fingerprint,
            'database_name' => $context->databaseName,
            'database_host' => $context->databaseHost,
            'document_root' => $context->documentRoot,
            'runtime_mode' => $context->runtimeMode,
            'db_host' => $context->runtimeMode === 'standalone-mysql' ? ($options->string('db-host', 'localhost') ?? 'localhost') : null,
            'db_port' => $context->runtimeMode === 'standalone-mysql' ? $options->integer('db-port', 3306) : null,
            'db_user' => $context->runtimeMode === 'standalone-mysql' ? ($options->string('db-user', '') ?? '') : null,
            'operation_index' => 0,
            'operation_state' => [],
        ]);
        $store->acquireLock($runId);
        $console->info("Запуск: {$runId}");
        $console->info("База: {$context->databaseName} на {$context->databaseHost}");
        $console->info('Собираю схему и количества только для выбранных блоков. Значения ПДн не выгружаются.');

        $builder = new PlanBuilder($context, $schema, $store, $console);
        $built = $builder->build($runId, $selectedBlocks, $chatMode, $nonAdminLoginMode);
        $state = $store->loadState($runId);
        $state['status'] = 'waiting_apply';
        $state['phase'] = 'dry_run_complete';
        $state['inventory_completed_at'] = date(DATE_ATOM);
        $store->saveState($runId, $state);
        $this->printInventorySummary($console, $store, $runId, $built['inventory'], $built['plan']);
        $this->printAccessNotes($console, $store, $runId);

        $reviews = (int)($built['inventory']['user_fields_summary']['review'] ?? 0);
        if ($reviews > 0) {
            $console->warning('Apply приостановлен до решения по всем UF-полям со статусом review.');
            $console->line('После редактирования выполните: ' . $this->resumeCommand($runId, $context, $state));
            return $options->has('apply') ? 2 : 0;
        }

        $apply = $options->has('apply');
        if (!$apply && $console->isInteractive()) {
            $answer = strtolower($console->prompt('Перейти к необратимому apply в этом же запуске? да/нет', 'нет'));
            $apply = in_array($answer, ['да', 'yes', 'y'], true);
        }
        if (!$apply) {
            $console->line();
            $console->success($context->runtimeMode === 'standalone-mysql'
                ? 'Dry-run завершен, БД не изменялась.'
                : 'Dry-run завершен, БД и /upload не изменялись.');
            $console->line('Для применения: ' . $this->resumeCommand($runId, $context, $state));
            return 0;
        }

        return $this->apply($options, $console, $store, $context, $schema, $runId, $state, $built['plan']);
    }

    private function resume(Options $options, Console $console, StateStore $store): int
    {
        $requested = $options->string('resume');
        $runId = $requested === null || $requested === '' || $requested === 'latest'
            ? $store->latestRunId()
            : $requested;
        if ($runId === null) {
            throw new RuntimeException('Нет запуска для возобновления.');
        }

        $state = $store->loadState($runId);
        $store->acquireLock($runId);
        $context = $this->loadContext($options, $state);
        if (!hash_equals((string)($state['database_fingerprint'] ?? ''), $context->fingerprint)) {
            throw new RuntimeException(
                'Текущая база/корень не совпадают с dry-run. Создайте новый запуск для этой копии.'
            );
        }
        $schema = new SchemaInspector($context);

        if (($state['status'] ?? '') === 'completed') {
            $console->success("Запуск {$runId} уже завершен.");
            $this->printStatus($store, $console, $runId);
            return 0;
        }

        $planPath = $store->artifactPath($runId, 'plan.json');
        if (!is_file($planPath)) {
            $console->warning('План не был сохранен; повторяю безопасную инвентаризацию.');
            $builder = new PlanBuilder($context, $schema, $store, $console);
            $builder->build(
                $runId,
                array_values((array)($state['selected_blocks'] ?? [])),
                (string)($state['chat_mode'] ?? 'clear'),
                (string)($state['non_admin_login_mode'] ?? 'anonymize')
            );
            $state['status'] = 'waiting_apply';
            $state['phase'] = 'dry_run_complete';
            $store->saveState($runId, $state);
        }
        $plan = $store->loadArtifact($runId, 'plan.json');
        $this->assertPlanIntegrity($plan);
        $this->assertRequestedLoginModeMatchesPlan($options, $plan);

        $apply = $options->has('apply');
        if (!$apply && $console->isInteractive()) {
            $answer = strtolower($console->prompt('Возобновить apply для этого запуска? да/нет', 'нет'));
            $apply = in_array($answer, ['да', 'yes', 'y'], true);
        }
        if (!$apply) {
            $this->printStatus($store, $console, $runId);
            $console->line("Для продолжения изменений добавьте --apply к --resume={$runId}.");
            return 0;
        }

        return $this->apply($options, $console, $store, $context, $schema, $runId, $state, $plan);
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $plan */
    private function apply(
        Options $options,
        Console $console,
        StateStore $store,
        BitrixContext $context,
        SchemaInspector $schema,
        string $runId,
        array $state,
        array $plan,
    ): int {
        $this->assertPlanIntegrity($plan);
        $this->assertAdministratorLoginPolicy($plan);
        if ($this->userFieldsOperationIsPending($plan, $state)) {
            (new UserFieldPlanner($context, $schema, $store, $runId))->loadAndValidateDecisions();
        }

        $this->confirmApply($options, $console, $context, $plan);
        $state['mode'] = 'apply';
        $state['status'] = 'running';
        $state['phase'] = 'apply';
        $state['apply_started_at'] = $state['apply_started_at'] ?? date(DATE_ATOM);
        $store->saveState($runId, $state);

        $batchSize = $options->has('batch-size')
            ? $this->validatedBatchSize($options->integer('batch-size', 500))
            : $this->validatedBatchSize((int)($state['batch_size'] ?? 500));
        $runner = new Runner($context, $schema, $store, $console, $runId, $batchSize);
        $runner->execute($state, $plan);

        $console->line();
        $console->success("Обезличивание завершено. Отчеты: {$store->runDir($runId)}");
        if (is_file($store->artifactPath($runId, 'verification.json'))) {
            $verification = $store->loadArtifact($runId, 'verification.json');
            $console->line('Статус проверки: ' . (string)($verification['status'] ?? 'unknown'));
        }
        $this->printAccessNotes($console, $store, $runId);
        return 0;
    }

    /** @param array<string, mixed> $plan */
    private function confirmApply(Options $options, Console $console, BitrixContext $context, array $plan): void
    {
        $console->line();
        $console->line('Собираюсь выполнить:');
        $console->line('  Операция: изменение и удаление данных');
        $object = "БД {$context->databaseName} на {$context->databaseHost}";
        if ($context->runtimeMode === 'bitrix') {
            $object .= "; связанные файлы в {$context->documentRoot}/upload";
        } else {
            $object .= '; standalone-режим, физический /upload отсутствует';
        }
        $console->line('  Объект: ' . $object);
        $console->line('  Что изменится: только выбранные блоки из plan.json, пакетами с контрольными точками');
        $console->line('  Обратимость: необратимо средствами скрипта; восстановление возможно только из внешнего бэкапа копии');
        $console->line('  Выбранные блоки: ' . implode(', ', (array)($plan['selected_blocks'] ?? [])));
        if (in_array('users', (array)($plan['selected_blocks'] ?? []), true)) {
            $nonAdminLoginMode = (string)($plan['non_admin_login_mode'] ?? 'anonymize');
            $console->line('  LOGIN администраторов: сохраняются всегда');
            $console->line('  LOGIN остальных пользователей: ' . ($nonAdminLoginMode === 'keep'
                ? 'сохраняются'
                : 'заменяются техническими'));
        }
        $console->line();

        if ($console->isInteractive()) {
            $console->requirePhrase(
                'Подтвердите точное имя базы, которую нужно очистить.',
                $context->databaseName
            );
            return;
        }

        if (!hash_equals($context->databaseName, (string)$options->string('confirm-copy', ''))) {
            throw new RuntimeException(
                'Для неинтерактивного apply нужен --confirm-copy=<точное имя БД>.'
            );
        }
    }

    /** @param array<string, mixed> $catalog */
    private function assertSelectedBlocks(array $selectedBlocks, array $catalog): void
    {
        if ($selectedBlocks === []) {
            throw new RuntimeException('Не выбран ни один блок.');
        }
        foreach ($selectedBlocks as $block) {
            if (!isset($catalog[$block])) {
                throw new RuntimeException("Неизвестный блок: {$block}");
            }
        }
    }

    private function assertChatMode(string $chatMode): void
    {
        if (!in_array($chatMode, ['clear', 'delete'], true)) {
            throw new RuntimeException('Режим чатов должен быть clear или delete.');
        }
    }

    private function assertNonAdminLoginMode(string $mode): void
    {
        if (!in_array($mode, ['anonymize', 'keep'], true)) {
            throw new RuntimeException(
                'Режим логинов не-администраторов должен быть anonymize или keep.'
            );
        }
    }

    /** @param array<string,mixed> $plan */
    private function assertAdministratorLoginPolicy(array $plan): void
    {
        if (!in_array('users', (array)($plan['selected_blocks'] ?? []), true)) {
            return;
        }
        if (!array_key_exists('non_admin_login_mode', $plan)) {
            throw new RuntimeException(
                'Старый план не гарантирует сохранение LOGIN администраторов. Создайте новый dry-run.'
            );
        }

        $mode = (string)$plan['non_admin_login_mode'];
        $this->assertNonAdminLoginMode($mode);
        foreach ((array)($plan['operations'] ?? []) as $operation) {
            if (!is_array($operation)
                || ($operation['type'] ?? '') !== 'update'
                || ($operation['table'] ?? '') !== 'b_user') {
                continue;
            }
            $set = (array)($operation['set'] ?? []);
            $loginMode = is_array($set['LOGIN'] ?? null) ? (string)($set['LOGIN']['mode'] ?? '') : null;
            if ($mode === 'keep' && $loginMode !== null) {
                throw new RuntimeException('План keep не должен изменять b_user.LOGIN. Создайте новый dry-run.');
            }
            if ($mode === 'anonymize'
                && $loginMode !== null
                && $loginMode !== 'run_login_except_administrators') {
                throw new RuntimeException(
                    'План anonymize не гарантирует сохранение LOGIN администраторов. Создайте новый dry-run.'
                );
            }
        }
    }

    /** @param array<string,mixed> $plan */
    private function assertRequestedLoginModeMatchesPlan(Options $options, array $plan): void
    {
        if (!$options->has('non-admin-login-mode')) {
            return;
        }
        $requested = strtolower($options->string('non-admin-login-mode', '') ?? '');
        $this->assertNonAdminLoginMode($requested);
        $planned = (string)($plan['non_admin_login_mode'] ?? 'legacy');
        if ($requested !== $planned) {
            throw new RuntimeException(
                'Режим логинов уже зафиксирован в plan.json как '
                . $planned . '; для изменения создайте новый dry-run.'
            );
        }
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $state */
    private function userFieldsOperationIsPending(array $plan, array $state): bool
    {
        $currentIndex = (int)($state['operation_index'] ?? 0);
        foreach ((array)($plan['operations'] ?? []) as $index => $operation) {
            if (is_array($operation)
                && ($operation['type'] ?? '') === 'special'
                && ($operation['name'] ?? '') === 'user_fields') {
                return (int)$index >= $currentIndex;
            }
        }
        return false;
    }

    private function validatedBatchSize(int $batchSize): int
    {
        if ($batchSize < 10 || $batchSize > 10000) {
            throw new RuntimeException('--batch-size должен быть от 10 до 10000.');
        }
        return $batchSize;
    }

    /** @param array<string,mixed> $state */
    private function loadContext(Options $options, array $state = []): BitrixContext
    {
        if ($options->has('standalone-mysql') && $options->has('root')) {
            throw new RuntimeException('--standalone-mysql нельзя совмещать с --root.');
        }

        $runtimeMode = $options->has('standalone-mysql')
            ? 'standalone-mysql'
            : (string)($state['runtime_mode'] ?? 'bitrix');
        if ($runtimeMode === 'standalone-mysql') {
            $host = $options->string('db-host', (string)($state['db_host'] ?? 'localhost')) ?? 'localhost';
            $port = $options->integer('db-port', (int)($state['db_port'] ?? 3306));
            $user = $options->string('db-user', (string)($state['db_user'] ?? '')) ?? '';
            $database = $options->string('db-name', (string)($state['database_name'] ?? '')) ?? '';
            $password = $options->string('db-password');
            if ($password === null) {
                $environmentPassword = getenv('PII_MYSQL_PASSWORD');
                $password = $environmentPassword === false ? null : $environmentPassword;
            }
            if ($user === '' || $database === '' || $password === null) {
                throw new RuntimeException(
                    'Standalone MySQL требует --db-user, --db-name и переменную окружения PII_MYSQL_PASSWORD.'
                );
            }
            return StandaloneMysqlBootstrap::load($host, $port, $user, $password, $database);
        }

        $root = $options->string('root', (string)($state['document_root'] ?? (getenv('BITRIX_ROOT') ?: getcwd())));
        if ($root === null || $root === '') {
            throw new RuntimeException('Передайте корень сайта Bitrix через --root=/path/to/site.');
        }
        return BitrixBootstrap::load($root);
    }

    /** @param array<string,mixed> $state */
    private function resumeCommand(string $runId, BitrixContext $context, array $state): string
    {
        if ($context->runtimeMode === 'standalone-mysql') {
            return 'php bin/pii-sanitize.php --standalone-mysql'
                . ' --db-host=' . (string)($state['db_host'] ?? 'localhost')
                . ' --db-port=' . (int)($state['db_port'] ?? 3306)
                . ' --db-user=' . (string)($state['db_user'] ?? '')
                . ' --db-name=' . $context->databaseName
                . " --resume={$runId} --apply";
        }
        return "php bin/pii-sanitize.php --root=\"{$context->documentRoot}\" --resume={$runId} --apply";
    }

    /** @param array<string,mixed> $plan */
    private function assertPlanIntegrity(array $plan): void
    {
        $expected = (string)($plan['integrity_hash'] ?? '');
        if ($expected === '') {
            throw new RuntimeException('В plan.json отсутствует контрольная сумма. Повторите dry-run новым запуском.');
        }
        unset($plan['integrity_hash']);
        $actual = hash(
            'sha256',
            json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('plan.json был изменен после dry-run. Apply запрещен; создайте новый запуск.');
        }
    }

    /** @param array<string, mixed> $inventory @param array<string, mixed> $plan */
    private function printInventorySummary(Console $console, StateStore $store, string $runId, array $inventory, array $plan): void
    {
        $operations = (array)($inventory['operations'] ?? []);
        $skipped = (array)($inventory['skipped'] ?? []);
        $estimated = 0;
        foreach ($operations as $operation) {
            if (is_array($operation) && is_int($operation['estimated_rows'] ?? null)) {
                $estimated += $operation['estimated_rows'];
            }
        }
        $console->line();
        $console->success('Инвентаризация завершена.');
        $console->line('Операций в плане: ' . count((array)($plan['operations'] ?? [])));
        $console->line("Сумма оценок строк по операциям: {$estimated} (одна строка может учитываться в нескольких операциях)");
        $console->line('Пропущено отсутствующих таблиц/колонок: ' . count($skipped));
        $console->line('План: ' . $store->artifactPath($runId, 'plan.json'));
        $console->line('Инвентаризация: ' . $store->artifactPath($runId, 'inventory.json'));

        $uf = (array)($inventory['user_fields_summary'] ?? []);
        if ((int)($uf['review'] ?? 0) > 0) {
            $console->warning(
                'Есть неоднозначные UF-поля: ' . (int)$uf['review']
                . '. Перед apply укажите clear/keep в ' . $store->artifactPath($runId, 'user_fields_decisions.json')
            );
        }
    }

    private function printRuns(StateStore $store, Console $console): void
    {
        $runs = $store->listRuns();
        if ($runs === []) {
            $console->line('Сохраненных запусков нет.');
            return;
        }
        foreach ($runs as $runId) {
            $state = $store->loadState($runId);
            $console->line(sprintf(
                '%s  status=%s  phase=%s  db=%s  updated=%s',
                $runId,
                (string)($state['status'] ?? 'unknown'),
                (string)($state['phase'] ?? 'unknown'),
                (string)($state['database_name'] ?? 'unknown'),
                (string)($state['updated_at'] ?? 'unknown'),
            ));
        }
    }

    private function printAccessNotes(Console $console, StateStore $store, string $runId): void
    {
        $path = $store->artifactPath($runId, 'access_notes.json');
        if (!is_file($path)) {
            return;
        }

        $notes = $store->loadArtifact($runId, 'access_notes.json');
        $administratorIds = array_map('intval', (array)($notes['preserved_administrator_user_ids'] ?? []));
        $console->line();
        if (($notes['login_strategy'] ?? '') === 'preserve_all') {
            $console->warning('ПОЛИТИКА ЛОГИНОВ: LOGIN всех пользователей сохраняются без изменения.');
            if ($administratorIds !== []) {
                $console->line('Администраторы с безусловно сохраняемым LOGIN, ID: ' . implode(', ', $administratorIds));
            }
            $console->line('Вход всех пользователей: прежние логин и пароль.');
        } elseif (($notes['login_strategy'] ?? '') === 'preserve_administrator_group_1') {
            $console->warning(
                'ПОЛИТИКА ЛОГИНОВ: логины администраторов сохраняются; у остальных пользователей заменяются.'
            );
            if ($administratorIds !== []) {
                $console->line('Сохраняемые администраторы, ID: ' . implode(', ', $administratorIds));
            } elseif (($notes['administrator_group_lookup_available'] ?? false) === true) {
                $console->warning('В административной группе ID 1 нет пользователей; сохраняемых LOGIN не найдено.');
            } else {
                $console->warning('Таблица административной группы не найдена; скрипт сохранит все LOGIN.');
            }
            $console->line(
                'Шаблон логина остальных пользователей: '
                . (string)($notes['login_pattern_non_administrators'] ?? 'не определен')
            );
            $console->line('Вход администратора: прежние логин и пароль.');
        } else {
            $console->warning('ДОСТУП ПОСЛЕ APPLY: этот старый план заменяет логины всех пользователей.');
            $console->line('Шаблон нового логина: ' . (string)($notes['login_pattern'] ?? 'не определен'));
        }
        foreach ((array)($notes['administrator_logins'] ?? []) as $administrator) {
            if (is_array($administrator)) {
                $console->line(sprintf(
                    'Администратор ID %d: %s',
                    (int)($administrator['user_id'] ?? 0),
                    (string)($administrator['login'] ?? '')
                ));
            }
        }
        $console->line('Хеш PASSWORD скрипт не меняет.');
        $console->line('Памятка доступа: ' . $path);
    }

    private function printStatus(StateStore $store, Console $console, string $runId): void
    {
        $state = $store->loadState($runId);
        $console->line("Запуск: {$runId}");
        $console->line('Статус: ' . (string)($state['status'] ?? 'unknown'));
        $console->line('Фаза: ' . (string)($state['phase'] ?? 'unknown'));
        $console->line('База: ' . (string)($state['database_name'] ?? 'unknown') . '@' . (string)($state['database_host'] ?? 'unknown'));
        $console->line('Логины не-администраторов: ' . (string)($state['non_admin_login_mode'] ?? 'legacy'));
        $console->line('Операция: ' . (int)($state['operation_index'] ?? 0) . ', текущая: ' . (string)($state['current_operation_id'] ?? '-'));
        $console->line('Обновлено: ' . (string)($state['updated_at'] ?? 'unknown'));
        if (isset($state['last_error'])) {
            $console->line('Последняя ошибка: ' . (string)$state['last_error']);
        }
        $console->line('Каталог отчетов: ' . $store->runDir($runId));
        $this->printAccessNotes($console, $store, $runId);
    }

    private function printHelp(Console $console): void
    {
        $console->line('Обезличивание копии Битрикс24');
        $console->line();
        $console->line('Первый запуск (интерактивный dry-run):');
        $console->line('  php bin/pii-sanitize.php --root=/path/to/bitrix24');
        $console->line('  PII_MYSQL_PASSWORD=... php bin/pii-sanitize.php --standalone-mysql --db-host=localhost --db-user=user --db-name=copy');
        $console->line();
        $console->line('Применить сохраненный план:');
        $console->line('  php bin/pii-sanitize.php --resume=<run-id> --apply');
        $console->line();
        $console->line('Основные параметры:');
        $console->line('  --blocks=crm_core,mail,...    выбрать блоки без меню');
        $console->line('  --chat-mode=clear|delete      сохранить или удалить сами чаты');
        $console->line('  --non-admin-login-mode=...    anonymize или keep; LOGIN админов всегда сохраняются');
        $console->line('  --batch-size=500              размер порции, 10..10000');
        $console->line('  --state-dir=/secure/path      каталог контрольных точек и отчетов');
        $console->line('  --resume=latest               продолжить последний запуск');
        $console->line('  --list-runs                   показать сохраненные запуски');
        $console->line('  --status=<run-id>             показать статус');
        $console->line('  --non-interactive             запретить запросы STDIN');
        $console->line('  --standalone-mysql            работать без файлов коробки, только с MySQL-копией');
        $console->line('  --db-host/port/user/name      параметры standalone MySQL; пароль через PII_MYSQL_PASSWORD');
        $console->line();
        $console->line('Неинтерактивный apply дополнительно требует:');
        $console->line('  --confirm-copy=<имя БД>');
    }
}
