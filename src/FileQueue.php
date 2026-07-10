<?php

declare(strict_types=1);

namespace Keyzy\Pii;

final class FileQueue
{
    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
        private readonly StateStore $store,
        private readonly string $runId,
    ) {
    }

    /**
     * @param array<int, array{source_table:string,source_column:string}> $sourcesById
     * @return int Число поставленных в очередь существующих b_file.ID.
     */
    public function enqueue(array $sourcesById): int
    {
        if ($sourcesById === [] || !$this->schema->hasTable('b_file') || !$this->schema->hasColumn('b_file', 'ID')) {
            return 0;
        }

        $ids = array_values(array_filter(array_map('intval', array_keys($sourcesById)), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $available = array_filter(
            ['ID', 'MODULE_ID', 'SUBDIR', 'FILE_NAME', 'FILE_SIZE', 'CONTENT_TYPE'],
            fn(string $column): bool => $this->schema->hasColumn('b_file', $column)
        );
        $quotedColumns = array_map(fn(string $column): string => $this->schema->quoteColumn('b_file', $column), $available);
        $sql = 'SELECT ' . implode(', ', $quotedColumns)
            . ' FROM ' . $this->schema->quoteTable('b_file')
            . ' WHERE ' . $this->schema->quoteColumn('b_file', 'ID')
            . ' IN (' . implode(', ', $ids) . ')';

        $queued = 0;
        $result = $this->context->connection->query($sql);
        while ($row = $result->fetch()) {
            $id = (int)$row['ID'];
            $source = $sourcesById[$id] ?? ['source_table' => 'unknown', 'source_column' => 'unknown'];
            $this->store->appendJsonLine($this->runId, 'file_queue.jsonl', [
                'file_id' => $id,
                'source_table' => $source['source_table'],
                'source_column' => $source['source_column'],
            ]);
            $relativePath = trim((string)($row['SUBDIR'] ?? ''), '/\\') . '/' . ltrim((string)($row['FILE_NAME'] ?? ''), '/\\');
            $this->store->appendJsonLine($this->runId, 'file_manifest_sensitive.jsonl', [
                'file_id' => $id,
                'module_id' => (string)($row['MODULE_ID'] ?? ''),
                'relative_path' => ltrim(str_replace('\\', '/', $relativePath), '/'),
                'file_size' => isset($row['FILE_SIZE']) ? (int)$row['FILE_SIZE'] : null,
                'content_type' => (string)($row['CONTENT_TYPE'] ?? ''),
                'source_table' => $source['source_table'],
                'source_column' => $source['source_column'],
            ]);
            $queued++;
        }

        return $queued;
    }
}
