<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class Console
{
    public function __construct(private readonly bool $interactive)
    {
    }

    public function isInteractive(): bool
    {
        return $this->interactive;
    }

    public function line(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public function info(string $message): void
    {
        $this->line('[ИНФО] ' . $message);
    }

    public function warning(string $message): void
    {
        $this->line('[ВНИМАНИЕ] ' . $message);
    }

    public function success(string $message): void
    {
        $this->line('[ГОТОВО] ' . $message);
    }

    public function prompt(string $question, ?string $default = null): string
    {
        if (!$this->interactive) {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException("Нужен интерактивный ввод: {$question}");
        }

        $suffix = $default === null ? ': ' : " [{$default}]: ";
        fwrite(STDOUT, $question . $suffix);
        $line = fgets(STDIN);
        if ($line === false) {
            throw new RuntimeException('Не удалось прочитать ввод из STDIN.');
        }

        $value = trim($line);
        return $value === '' && $default !== null ? $default : $value;
    }

    public function requirePhrase(string $message, string $phrase): void
    {
        $this->line($message);
        $actual = $this->prompt("Введите точно: {$phrase}");
        if (!hash_equals($phrase, $actual)) {
            throw new RuntimeException('Подтверждение не совпало. Изменения не выполнялись.');
        }
    }

    /**
     * @param array<string, array{title:string, description:string, default:bool}> $blocks
     * @return list<string>
     */
    public function checklist(array $blocks): array
    {
        if (!$this->interactive) {
            throw new RuntimeException('В неинтерактивном режиме передайте --blocks=id1,id2.');
        }

        $ids = array_keys($blocks);
        $selected = [];
        foreach ($blocks as $id => $block) {
            $selected[$id] = $block['default'];
        }

        while (true) {
            $this->line();
            $this->line('Блоки обезличивания:');
            foreach ($ids as $number => $id) {
                $mark = $selected[$id] ? 'x' : ' ';
                $block = $blocks[$id];
                $this->line(sprintf('  %2d. [%s] %s', $number + 1, $mark, $block['title']));
                $this->line('      ' . $block['description']);
            }
            $this->line();
            $this->line('Введите номера через запятую, чтобы переключить; a = все и продолжить, n = ничего, Enter = продолжить.');
            $answer = strtolower($this->prompt('Выбор', ''));
            if ($answer === '') {
                break;
            }
            if ($answer === 'a') {
                foreach ($selected as $id => $_value) {
                    $selected[$id] = true;
                }
                break;
            }
            if ($answer === 'n') {
                foreach ($selected as $id => $_value) {
                    $selected[$id] = false;
                }
                continue;
            }

            foreach (preg_split('/[\s,;]+/', $answer) ?: [] as $token) {
                if ($token === '' || !ctype_digit($token)) {
                    $this->warning("Пропущено неизвестное значение: {$token}");
                    continue;
                }
                $position = (int)$token - 1;
                if (!isset($ids[$position])) {
                    $this->warning("Нет блока с номером {$token}.");
                    continue;
                }
                $id = $ids[$position];
                $selected[$id] = !$selected[$id];
            }
        }

        $result = [];
        foreach ($selected as $id => $enabled) {
            if ($enabled) {
                $result[] = $id;
            }
        }
        if ($result === []) {
            throw new RuntimeException('Не выбран ни один блок.');
        }

        return $result;
    }
}
