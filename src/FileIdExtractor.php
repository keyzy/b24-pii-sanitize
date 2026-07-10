<?php

declare(strict_types=1);

namespace Keyzy\Pii;

final class FileIdExtractor
{
    /** @return list<int> */
    public static function extract(mixed $value): array
    {
        $ids = [];
        self::walk($value, $ids);
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<int, true> $ids */
    private static function walk(mixed $value, array &$ids): void
    {
        if (is_int($value)) {
            if ($value > 0) {
                $ids[$value] = true;
            }
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::walk($item, $ids);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $value = trim($value);
        if ($value === '') {
            return;
        }
        if (ctype_digit($value)) {
            self::walk((int)$value, $ids);
            return;
        }

        if (($value[0] === '[' || $value[0] === '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                self::walk($decoded, $ids);
                return;
            }
        }

        if (preg_match('/^(a|i|s|d|b|N):/', $value) === 1) {
            $decoded = @unserialize($value, ['allowed_classes' => false]);
            if ($decoded !== false || $value === 'b:0;') {
                self::walk($decoded, $ids);
                return;
            }
        }

        if (preg_match('/^[0-9]+(?:[\s,;|]+[0-9]+)+$/', $value) === 1) {
            foreach (preg_split('/[\s,;|]+/', $value) ?: [] as $item) {
                self::walk((int)$item, $ids);
            }
        }
    }
}
