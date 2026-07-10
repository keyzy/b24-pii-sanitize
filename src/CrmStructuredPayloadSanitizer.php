<?php

declare(strict_types=1);

namespace Keyzy\Pii;

final class CrmStructuredPayloadSanitizer
{
    private const PLACEHOLDER = 'Обезличено';

    public static function sanitizeSerializedArray(string $raw, bool $required = false): string
    {
        if ($raw === '') {
            return $required ? serialize([]) : '';
        }

        $value = self::unserializeArray($raw);
        if ($value === null) {
            return $required ? serialize([]) : '';
        }

        return serialize(self::sanitizeValue($value));
    }

    public static function sanitizeEncodedArray(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $serialized = self::unserializeArray($raw);
        if ($serialized !== null) {
            return serialize(self::sanitizeValue($serialized));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        $sanitized = self::sanitizeValue($decoded);
        if ($sanitized === [] && str_starts_with(ltrim($raw), '{')) {
            return '{}';
        }

        $encoded = json_encode(
            $sanitized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        return is_string($encoded) ? $encoded : '{}';
    }

    /** @param list<string> $path */
    public static function sanitizeValue(mixed $value, array $path = []): mixed
    {
        $key = $path === [] ? '' : $path[array_key_last($path)];

        if (is_array($value)) {
            if (self::isFileContainer($key)) {
                return [];
            }
            $result = [];
            foreach ($value as $childKey => $childValue) {
                $normalizedKey = is_int($childKey) || ctype_digit((string)$childKey)
                    ? '[]'
                    : strtoupper((string)$childKey);
                $result[$childKey] = self::sanitizeValue($childValue, [...$path, $normalizedKey]);
            }
            return $result;
        }

        if (is_string($value)) {
            if ($key === 'HAS_FILES') {
                return 'N';
            }
            if (preg_match('/(?:NAME|TITLE|CAPTION|SUBJECT)$/i', $key) === 1) {
                return self::PLACEHOLDER;
            }
            if (!self::isSafeTechnicalStringKey($key)) {
                return '';
            }
            if (in_array($key, ['TASK_CURR_DEADLINE', 'TASK_PREV_DEADLINE'], true)) {
                return $value;
            }
            return self::containsEmailOrPhone($value) ? '' : $value;
        }

        if (is_int($value) || is_float($value)) {
            return self::pathContainsSensitiveScalar($path) ? 0 : $value;
        }

        if (is_object($value)) {
            $class = get_class($value);
            if ($value instanceof \DateTimeInterface
                || in_array($class, ['Bitrix\\Main\\Type\\DateTime', 'Bitrix\\Main\\Type\\Date'], true)) {
                return $value;
            }
            return null;
        }

        return $value;
    }

    /** @return array<mixed>|null */
    private static function unserializeArray(string $raw): ?array
    {
        $value = @unserialize($raw, [
            'allowed_classes' => [
                \DateTime::class,
                'Bitrix\\Main\\Type\\DateTime',
                'Bitrix\\Main\\Type\\Date',
            ],
        ]);
        return is_array($value) ? $value : null;
    }

    private static function isFileContainer(string $key): bool
    {
        return preg_match('/(?:^|_)(?:FILES?|FILE_IDS?|ATTACHMENTS?|STORAGE_ELEMENT_IDS)$/i', $key) === 1;
    }

    private static function isSafeTechnicalStringKey(string $key): bool
    {
        if (in_array($key, [
            'TYPE', 'TYPEID', 'STATUS', 'ACTIVITY_STATUS', 'CODE', 'BADGECODE',
            'CLASS', 'COLOR', 'CURRENCY', 'FIELD', 'ACTION', 'SCOPE', 'MODE',
            'EFFICIENCY', 'WORKFLOW_ID', 'WORKFLOW_TEMPLATE_ID', 'DOCUMENT_ID',
            'TASK_ID', 'JOB_ID', 'CLIENTID', 'LETTERID', 'TASK_CURR_DEADLINE',
            'TASK_PREV_DEADLINE',
        ], true)) {
            return true;
        }

        return preg_match('/(?:^|_)(?:TYPE_ID|CATEGORY_ID|STAGE_ID|STATUS_ID)$/i', $key) === 1;
    }

    /** @param list<string> $path */
    private static function pathContainsSensitiveScalar(array $path): bool
    {
        foreach ($path as $segment) {
            if (in_array($segment, [
                'START', 'FINISH', 'VALUE', 'OLD_VALUE', 'NEW_VALUE', 'SUM',
                'PRICE', 'TOTAL', 'AMOUNT', 'PHONE', 'IP',
            ], true)) {
                return true;
            }
        }
        return false;
    }

    private static function containsEmailOrPhone(string $value): bool
    {
        return preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $value) === 1
            || preg_match('/(?:\+?\d[\d ()-]{8,}\d)/', $value) === 1;
    }
}
