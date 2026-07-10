# Очистка копии Битрикс24

## Текущая база Битрикс

Укажите корень сайта:

```bash
php -d display_startup_errors=0 bin/pii-sanitize.php --root=/path/to/bitrix24
```

Скрипт загрузит `bitrix/modules/main/include/prolog_before.php`, возьмёт подключение из настроек Bitrix и покажет фактическое имя базы. Логин и пароль MySQL не нужны.

Перед продолжением убедитесь, что показана копия, а не рабочая база.

Мастер спросит:

- какие блоки чистить;
- очищать ли задачи (это отдельный пункт);
- удалить чаты или только сообщения;
- менять ли логины обычных пользователей;
- занулять ли суммы CRM и цены товаров (по умолчанию выключено);
- очищать ли Маркетинг: рассылки и сегменты (по умолчанию выключено).

В меню блоков `a` выбирает всё и сразу продолжает, Enter принимает текущие галочки.

Задачи не удаляются, в них обезличиваются заголовки, описания и комментарии.
Таймлайн и старая история CRM сохраняются, пользовательские тексты внутри них обезличиваются.

Логины администраторов не меняются при любом выборе. Хеши паролей сохраняются.

После dry-run сохраните показанный `run-id`. Если в `user_fields_decisions.json` остались поля `review`, замените их на `clear` или `keep`.

Запуск очистки:

```bash
RUN_ID=your-run-id
php -d display_startup_errors=0 bin/pii-sanitize.php --resume="$RUN_ID" --apply
```

Введите точное имя базы, которое покажет мастер. Например:

```text
bitrix_copy
```

При сбое повторите ту же команду с тем же `run-id`. Скрипт продолжит с контрольной точки.

## Другая база

Если нужно подключиться не к базе из настроек Bitrix, используйте standalone:

```bash
read -rsp 'MySQL password: ' PII_MYSQL_PASSWORD; echo
export PII_MYSQL_PASSWORD

php -d display_startup_errors=0 bin/pii-sanitize.php --standalone-mysql \
  --db-host=localhost --db-port=3306 \
  --db-user=db_user --db-name=bitrix_copy
```

В PowerShell пароль задаётся так:

```powershell
$env:PII_MYSQL_PASSWORD = [Net.NetworkCredential]::new('', (Read-Host 'MySQL password' -AsSecureString)).Password
```

Standalone работает только с БД. Физический каталог `/upload` он не чистит.

## Результат

Файлы запуска лежат в `var/<run-id>`:

- `state.json` - статус и контрольная точка;
- `plan.json` - зафиксированный план;
- `verification.json` - итоговая проверка;
- `access_notes.json` - политика логинов.

Для другого каталога используйте `--state-dir=/secure/path` и повторяйте этот параметр в командах status/resume/apply.

Проверка всей базы на оставшиеся телефоны и почты:

```bash
php -d display_startup_errors=0 bin/audit-pii.php --root=/path/to/bitrix24
```


#Важно!
Программа предоставляется «как есть», без каких-либо гарантий. Использовать только на резервной копии базы данных. Автор не несёт ответственности за потерю или повреждение данных.
