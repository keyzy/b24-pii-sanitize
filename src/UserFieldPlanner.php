<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class UserFieldPlanner
{
    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
        private readonly StateStore $store,
        private readonly string $runId,
    ) {
    }

    /** @return array<string, mixed> */
    public function createDecisions(): array
    {
        $artifact = [
            'version' => 1,
            'instructions' => [
                'Меняйте только поле action: clear, keep или review.',
                'Apply не начнется, пока хотя бы у одного поля action=review.',
                'В файл не записываются значения пользовательских полей, только их метаданные.',
            ],
            'fields' => [],
        ];

        if (!$this->schema->hasTable('b_user_field')) {
            $this->store->saveArtifact($this->runId, 'user_fields_decisions.json', $artifact);
            return $artifact;
        }

        $columns = $this->schema->columns('b_user_field');
        $wanted = ['ID', 'ENTITY_ID', 'FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE', 'MANDATORY'];
        $selected = array_values(array_filter($wanted, static fn(string $name): bool => isset($columns[$name])));
        if (!in_array('ID', $selected, true) || !in_array('ENTITY_ID', $selected, true) || !in_array('FIELD_NAME', $selected, true)) {
            throw new RuntimeException('Таблица b_user_field не содержит обязательные служебные колонки.');
        }

        $quoted = array_map(fn(string $column): string => $this->schema->quoteColumn('b_user_field', $column), $selected);
        $table = $this->schema->quoteTable('b_user_field');
        $result = $this->context->connection->query(
            'SELECT ' . implode(', ', $quoted) . " FROM {$table} WHERE "
            . $this->schema->quoteColumn('b_user_field', 'ENTITY_ID')
            . " REGEXP '^(CRM|USER)' ORDER BY "
            . $this->schema->quoteColumn('b_user_field', 'ENTITY_ID') . ', '
            . $this->schema->quoteColumn('b_user_field', 'FIELD_NAME')
        );

        $labels = $this->loadLabels();
        $fields = [];
        while ($row = $result->fetch()) {
            $id = (int)$row['ID'];
            $labelList = $labels[$id] ?? [];
            [$action, $reason] = $this->proposeAction($row, $labelList);
            $fields[] = [
                'id' => $id,
                'entity_id' => (string)$row['ENTITY_ID'],
                'field_name' => (string)$row['FIELD_NAME'],
                'user_type_id' => (string)($row['USER_TYPE_ID'] ?? ''),
                'multiple' => (string)($row['MULTIPLE'] ?? 'N'),
                'mandatory' => (string)($row['MANDATORY'] ?? 'N'),
                'labels' => $labelList,
                'action' => $action,
                'reason' => $reason,
            ];
        }

        $artifact['fields'] = $fields;
        $artifact['summary'] = $this->summarize($fields);
        $this->store->saveArtifact($this->runId, 'user_fields_decisions.json', $artifact);
        return $artifact;
    }

    /** @return array<string, mixed> */
    public function loadAndValidateDecisions(): array
    {
        $artifact = $this->store->loadArtifact($this->runId, 'user_fields_decisions.json');
        $fields = $artifact['fields'] ?? null;
        if (!is_array($fields)) {
            throw new RuntimeException('В user_fields_decisions.json отсутствует массив fields.');
        }

        $reviews = [];
        foreach ($fields as $index => $field) {
            if (!is_array($field)) {
                throw new RuntimeException("Некорректная запись UF с индексом {$index}.");
            }
            $action = (string)($field['action'] ?? '');
            if (!in_array($action, ['clear', 'keep', 'review'], true)) {
                throw new RuntimeException("Недопустимое действие UF: {$action}");
            }
            if ($action === 'review') {
                $reviews[] = (string)($field['entity_id'] ?? '') . '.' . (string)($field['field_name'] ?? '');
            }
            if ((int)($field['id'] ?? 0) <= 0 || !preg_match('/^[A-Z0-9_]+$/i', (string)($field['field_name'] ?? ''))) {
                throw new RuntimeException("Некорректные метаданные UF с индексом {$index}.");
            }
        }

        if ($reviews !== []) {
            $preview = implode(', ', array_slice($reviews, 0, 10));
            $suffix = count($reviews) > 10 ? ' и еще ' . (count($reviews) - 10) : '';
            throw new RuntimeException(
                'Нужно принять решение по UF-полям в user_fields_decisions.json: ' . $preview . $suffix
            );
        }

        return $artifact;
    }

    /** @return array<int, list<string>> */
    private function loadLabels(): array
    {
        if (!$this->schema->hasTable('b_user_field_lang')) {
            return [];
        }

        $columns = $this->schema->columns('b_user_field_lang');
        if (!isset($columns['USER_FIELD_ID'])) {
            return [];
        }
        $labelColumns = array_values(array_filter(
            ['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL', 'ERROR_MESSAGE', 'HELP_MESSAGE'],
            static fn(string $name): bool => isset($columns[$name])
        ));
        if ($labelColumns === []) {
            return [];
        }

        $select = [$this->schema->quoteColumn('b_user_field_lang', 'USER_FIELD_ID')];
        foreach ($labelColumns as $column) {
            $select[] = $this->schema->quoteColumn('b_user_field_lang', $column);
        }
        $result = $this->context->connection->query(
            'SELECT ' . implode(', ', $select) . ' FROM ' . $this->schema->quoteTable('b_user_field_lang')
        );
        $labels = [];
        while ($row = $result->fetch()) {
            $id = (int)$row['USER_FIELD_ID'];
            foreach ($labelColumns as $column) {
                $value = trim((string)($row[$column] ?? ''));
                if ($value !== '') {
                    $labels[$id][$value] = true;
                }
            }
        }

        $resultLabels = [];
        foreach ($labels as $id => $values) {
            $resultLabels[$id] = array_keys($values);
            sort($resultLabels[$id], SORT_STRING);
        }
        return $resultLabels;
    }

    /**
     * @param array<string, mixed> $field
     * @param list<string> $labels
     * @return array{0:string,1:string}
     */
    private function proposeAction(array $field, array $labels): array
    {
        $haystack = implode(' ', [
            (string)($field['ENTITY_ID'] ?? ''),
            (string)($field['FIELD_NAME'] ?? ''),
            (string)($field['USER_TYPE_ID'] ?? ''),
            implode(' ', $labels),
        ]);

        $type = strtolower((string)($field['USER_TYPE_ID'] ?? ''));
        if (in_array($type, ['crm', 'employee', 'user', 'iblock_element', 'iblock_section', 'enumeration', 'boolean'], true)) {
            return ['keep', 'Поле хранит техническую связь или справочное значение; ID-связь сохраняется.'];
        }
        if ($type === 'file') {
            return ['clear', 'Файловое пользовательское поле: содержимое нельзя передавать без отдельной проверки.'];
        }

        $piiPattern = '/(ФИО|ИМЯ|ФАМИЛ|ОТЧЕСТВ|НАЗВАН|НАИМЕНОВАН|ЛОГИН|СКАЙП|ТЕЛЕФ|МОБИЛ|ПОЧТ|АДРЕС|ПАСПОРТ|ИНН|КПП|ОГРН|СНИЛС|ДАТА.?РОЖ|БАНК|СЧ[ЕЁ]Т|БИК|КАРТ|КЛИЕНТ|КОНТАКТ|СОЦ|ВКОНТАКТ|ТЕЛЕГРАМ|WHATSAPP|NAME|USERNAME|LOGIN|SKYPE|PHONE|MOBILE|EMAIL|MAIL|ADDRESS|PASSPORT|BIRTH|BANK|ACCOUNT|CLIENT|CONTACT|SOCIAL|TELEGRAM|COMMENT|MESSAGE|DESCRIPTION|PHOTO|FILE|DOCUMENT|REQUISITE)/ui';
        if (preg_match($piiPattern, $haystack) === 1) {
            return ['clear', 'Название или подпись поля указывает на ПДн/свободный текст.'];
        }

        if (in_array($type, ['string', 'url', 'address', 'datetime', 'date', 'integer', 'double'], true)) {
            return ['review', 'Тип поля может содержать ПДн, но назначение не определяется по метаданным.'];
        }

        return ['review', 'Нестандартный тип поля может хранить ПДн внутри собственного формата и требует явного решения.'];
    }

    /** @param list<array<string, mixed>> $fields */
    private function summarize(array $fields): array
    {
        $summary = ['clear' => 0, 'keep' => 0, 'review' => 0];
        foreach ($fields as $field) {
            $action = (string)($field['action'] ?? 'review');
            $summary[$action] = ($summary[$action] ?? 0) + 1;
        }
        return $summary;
    }
}
