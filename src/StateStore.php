<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class StateStore
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(private readonly string $baseDir)
    {
        $this->ensureDirectory($baseDir);
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /** @param array<string, mixed> $initialState */
    public function createRun(array $initialState): string
    {
        $runId = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $this->ensureDirectory($this->runDir($runId));
        $this->saveState($runId, $initialState + ['run_id' => $runId]);

        return $runId;
    }

    public function acquireLock(string $runId): void
    {
        $path = $this->runDir($runId) . '/run.lock';
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException("Запуск {$runId} уже выполняется другим процессом.");
        }
        $this->lockHandle = $handle;
    }

    /** @return array<string, mixed> */
    public function loadState(string $runId): array
    {
        return $this->readJson($this->runDir($runId) . '/state.json');
    }

    /** @param array<string, mixed> $state */
    public function saveState(string $runId, array $state): void
    {
        $state['updated_at'] = date(DATE_ATOM);
        $this->writeJson($this->runDir($runId) . '/state.json', $state);
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public function saveArtifact(string $runId, string $name, array $data): void
    {
        $this->assertArtifactName($name);
        $this->writeJson($this->runDir($runId) . '/' . $name, $data);
    }

    /** @return array<string, mixed>|list<mixed> */
    public function loadArtifact(string $runId, string $name): array
    {
        $this->assertArtifactName($name);
        return $this->readJson($this->runDir($runId) . '/' . $name);
    }

    /** @param array<string, mixed> $record */
    public function appendJsonLine(string $runId, string $name, array $record): void
    {
        $this->assertArtifactName($name);
        $record['timestamp'] = $record['timestamp'] ?? date(DATE_ATOM);
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->runDir($runId) . '/' . $name;
        if (file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Не удалось дописать файл {$path}.");
        }
    }

    public function artifactPath(string $runId, string $name): string
    {
        $this->assertArtifactName($name);
        return $this->runDir($runId) . '/' . $name;
    }

    public function runDir(string $runId): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $runId)) {
            throw new RuntimeException('Недопустимый идентификатор запуска.');
        }
        return rtrim($this->baseDir, '/\\') . '/' . $runId;
    }

    /** @return list<string> */
    public function listRuns(): array
    {
        $items = glob(rtrim($this->baseDir, '/\\') . '/*', GLOB_ONLYDIR) ?: [];
        $runs = [];
        foreach ($items as $item) {
            $id = basename($item);
            if (is_file($item . '/state.json')) {
                $runs[] = $id;
            }
        }
        rsort($runs, SORT_STRING);
        return $runs;
    }

    public function latestRunId(): ?string
    {
        return $this->listRuns()[0] ?? null;
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException("Не удалось создать каталог {$path}.");
        }
    }

    /** @param array<string, mixed>|list<mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException("Не удалось записать временный файл для {$path}.");
        }

        $backup = $path . '.bak';
        $hadOriginal = is_file($path);
        if ($hadOriginal) {
            if (is_file($backup) && !$this->retryUnlink($backup)) {
                @unlink($temporary);
                throw new RuntimeException("Не удалось удалить старую резервную копию {$backup}.");
            }
            if (!$this->retryRename($path, $backup)) {
                @unlink($temporary);
                throw new RuntimeException("Не удалось подготовить атомарную замену {$path}.");
            }
        }

        if (!$this->retryRename($temporary, $path)) {
            @unlink($temporary);
            if ($hadOriginal && is_file($backup)) {
                $this->retryRename($backup, $path);
            }
            throw new RuntimeException("Не удалось атомарно записать {$path}.");
        }
        if ($hadOriginal) {
            $this->retryUnlink($backup);
        }
    }

    /** @return array<string, mixed>|list<mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path) && is_file($path . '.bak')) {
            $path .= '.bak';
        }
        if (!is_file($path)) {
            throw new RuntimeException("Файл не найден: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать {$path}.");
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Некорректный JSON в {$path}.");
        }
        return $decoded;
    }

    private function assertArtifactName(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) {
            throw new RuntimeException("Недопустимое имя артефакта: {$name}");
        }
    }

    private function retryRename(string $from, string $to): bool
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    private function retryUnlink(string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (@unlink($path) || !is_file($path)) {
                return true;
            }
            usleep(100000);
        }
        return false;
    }
}
