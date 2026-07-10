<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use InvalidArgumentException;

final class Options
{
    /** @var array<string, string|bool> */
    private array $values;

    /** @param array<string, string|bool> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @param list<string> $argv */
    public static function parse(array $argv): self
    {
        $values = [];
        $expectsValue = [
            'root', 'state-dir', 'resume', 'status', 'blocks', 'batch-size',
            'chat-mode', 'non-admin-login-mode', 'confirm-copy',
            'db-host', 'db-port', 'db-user', 'db-password', 'db-name',
            'output-dir',
            'decisions',
        ];

        for ($index = 1, $count = count($argv); $index < $count; $index++) {
            $argument = $argv[$index];
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException("Неизвестный позиционный аргумент: {$argument}");
            }

            $argument = substr($argument, 2);
            if ($argument === '') {
                throw new InvalidArgumentException('Пустой параметр командной строки.');
            }

            if (str_contains($argument, '=')) {
                [$name, $value] = explode('=', $argument, 2);
                $values[$name] = $value;
                continue;
            }

            if (in_array($argument, $expectsValue, true)
                && isset($argv[$index + 1])
                && !str_starts_with($argv[$index + 1], '--')) {
                $values[$argument] = $argv[++$index];
                continue;
            }

            $values[$argument] = true;
        }

        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function string(string $name, ?string $default = null): ?string
    {
        if (!$this->has($name)) {
            return $default;
        }

        $value = $this->values[$name];
        if ($value === true) {
            return $default;
        }

        return trim($value);
    }

    public function integer(string $name, int $default): int
    {
        $value = $this->string($name);
        if ($value === null) {
            return $default;
        }
        if (!preg_match('/^[0-9]+$/', $value)) {
            throw new InvalidArgumentException("Параметр --{$name} должен быть целым положительным числом.");
        }

        return (int)$value;
    }

    /** @return list<string> */
    public function csv(string $name): array
    {
        $value = $this->string($name, '');
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }
}
