<?php

declare(strict_types=1);

namespace Keyzy\Pii;

use RuntimeException;

final class GlobalAuditor
{
    public function __construct(
        private readonly BitrixContext $context,
        private readonly SchemaInspector $schema,
        private readonly Console $console,
    ) {
    }

    /** @return array<string,mixed> */
    public function run(string $outputDir): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0770, true) && !is_dir($outputDir)) {
            throw new RuntimeException("Не удалось создать каталог аудита {$outputDir}.");
        }
        $findingsPath = rtrim($outputDir, '/\\') . '/global_findings.jsonl';
        $errorsPath = rtrim($outputDir, '/\\') . '/global_errors.jsonl';
        file_put_contents($findingsPath, '');
        file_put_contents($errorsPath, '');

        $targets = $this->loadTargets();
        $emailPattern = '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}';
        $phonePattern = '\\+?[0-9][0-9 ()-]{8,}[0-9]';
        $emailLiteral = $this->schema->literal($emailPattern);
        $allowedEmailLiteral = $this->schema->literal('@example\\.invalid');
        $phoneLiteral = $this->schema->literal($phonePattern);
        $emailHitsTotal = 0;
        $phoneHitsTotal = 0;
        $phonePiiHitsTotal = 0;
        $columnsWithFindings = 0;
        $errors = 0;

        foreach ($targets as $index => [$table, $column]) {
            $quotedTable = $this->schema->quoteTable($table);
            $quotedColumn = $this->schema->quoteColumn($table, $column);
            try {
                $row = $this->context->connection->query(
                    "SELECT "
                    . "SUM(CASE WHEN {$quotedColumn} REGEXP {$emailLiteral} AND {$quotedColumn} NOT REGEXP {$allowedEmailLiteral} THEN 1 ELSE 0 END) AS EMAIL_HITS, "
                    . "SUM(CASE WHEN {$quotedColumn} REGEXP {$phoneLiteral} THEN 1 ELSE 0 END) AS PHONE_HITS "
                    . "FROM {$quotedTable}"
                )->fetch();
                $emailHits = (int)($row['EMAIL_HITS'] ?? 0);
                $phoneHits = (int)($row['PHONE_HITS'] ?? 0);
                if ($emailHits > 0 || $phoneHits > 0) {
                    $highConfidencePhone = $this->isHighConfidencePhoneColumn($table, $column);
                    $this->appendJsonLine($findingsPath, [
                        'table' => $table,
                        'column' => $column,
                        'email_hits' => $emailHits,
                        'phone_hits' => $phoneHits,
                        'phone_high_confidence' => $highConfidencePhone,
                        'contains_values' => false,
                    ]);
                    $columnsWithFindings++;
                    $emailHitsTotal += $emailHits;
                    $phoneHitsTotal += $phoneHits;
                    if ($highConfidencePhone) {
                        $phonePiiHitsTotal += $phoneHits;
                    }
                }
            } catch (\Throwable $exception) {
                $this->appendJsonLine($errorsPath, [
                    'table' => $table,
                    'column' => $column,
                    'error' => $exception->getMessage(),
                ]);
                $errors++;
            }

            if (($index + 1) % 250 === 0) {
                $this->console->info(sprintf('Глобальный аудит: %d/%d колонок', $index + 1, count($targets)));
            }
        }

        $summary = [
            'version' => 1,
            'database' => $this->context->databaseName,
            'database_fingerprint' => $this->context->fingerprint,
            'completed_at' => date(DATE_ATOM),
            'scanned_text_columns' => count($targets),
            'columns_with_findings' => $columnsWithFindings,
            'email_hits' => $emailHitsTotal,
            'phone_hits' => $phoneHitsTotal,
            'phone_high_confidence_hits' => $phonePiiHitsTotal,
            'errors' => $errors,
            'contains_values' => false,
            'status' => $emailHitsTotal === 0 && $phonePiiHitsTotal === 0 && $errors === 0 ? 'passed' : 'warnings',
        ];
        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents(rtrim($outputDir, '/\\') . '/global_summary.json', $json . PHP_EOL, LOCK_EX);
        return $summary;
    }

    /** @return list<array{0:string,1:string}> */
    private function loadTargets(): array
    {
        $result = $this->context->connection->query(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS c
             INNER JOIN INFORMATION_SCHEMA.TABLES t
                ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND t.TABLE_TYPE = 'BASE TABLE'
               AND c.DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext')
             ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION"
        );
        $targets = [];
        while ($row = $result->fetch()) {
            $targets[] = [(string)$row['TABLE_NAME'], (string)$row['COLUMN_NAME']];
        }
        return $targets;
    }

    private function isHighConfidencePhoneColumn(string $table, string $column): bool
    {
        if (preg_match('/(PHONE|MOBILE|CALL_NUMBER|CALLER_ID|CALLED|PORTAL_NUMBER|INCOMING_PHONE|NORMALIZED_NUMBER)/i', $column) === 1) {
            return true;
        }
        if ($column === 'NUMBER' && preg_match('/^b_(voximplant|sender|crm|im|imopenlines)/', $table) === 1) {
            return true;
        }
        if ($column === 'VALUE' && in_array($table, ['b_crm_field_multi', 'b_crm_tracking_phone_number'], true)) {
            return true;
        }
        if (preg_match('/^(SEARCH_CONTENT|SEARCH_INDEX|SEARCH_TITLE|FULL_NAME)$/', $column) === 1
            && preg_match('/^b_(crm|calendar|tasks|sonet|im|imopenlines|disk|landing)/', $table) === 1) {
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $record */
    private function appendJsonLine(string $path, array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Не удалось записать {$path}.");
        }
    }
}
