<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportIssueLevel;
use App\Models\ImportIssue;

/**
 * Collects the errors and warnings of an import step and writes them to `import_issues` in bulk.
 */
final class IssueBatch
{
    public const MESSAGE_LENGTH = 1000;

    public const VALUE_LENGTH = 500;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private int $errors = 0;

    private int $warnings = 0;

    public function __construct(private readonly int $importId) {}

    public function add(int $row, ImportIssueLevel $level, ?string $column, string $message, ?string $value = null): void
    {
        $this->rows[] = [
            'import_id' => $this->importId,
            'row_number' => $row,
            'column' => $column !== null && $column !== '' ? mb_substr($column, 0, 255) : null,
            'level' => $level->value,
            'message' => mb_substr($message, 0, self::MESSAGE_LENGTH),
            'value' => $value !== null ? mb_substr($value, 0, self::VALUE_LENGTH) : null,
            'created_at' => now(),
        ];

        if ($level === ImportIssueLevel::Error) {
            $this->errors++;
        } else {
            $this->warnings++;
        }
    }

    /**
     * Add an issue as staged by the analyzer: {field, column, message, value}.
     *
     * @param  array<string, mixed>  $issue
     */
    public function addStaged(int $row, ImportIssueLevel $level, array $issue, ?string $columnPrefix = null): void
    {
        $column = (string) ($issue['column'] ?? '');

        if ($columnPrefix !== null) {
            $column = $columnPrefix.($column !== '' ? ' / '.$column : '');
        }

        $value = $issue['value'] ?? null;

        $this->add($row, $level, $column, (string) ($issue['message'] ?? ''), is_string($value) ? $value : null);
    }

    public function errorCount(): int
    {
        return $this->errors;
    }

    public function warningCount(): int
    {
        return $this->warnings;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Insert everything collected so far and empty the batch.
     */
    public function flush(): void
    {
        foreach (array_chunk($this->rows, 200) as $chunk) {
            ImportIssue::query()->insert($chunk);
        }

        $this->rows = [];
    }
}
