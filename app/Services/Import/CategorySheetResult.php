<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Outcome of {@see CategorySheetImporter::import()}.
 */
final readonly class CategorySheetResult
{
    /**
     * @param  list<array<string, mixed>>  $photoTasks  `{type: 'category', id, refs, mode, row}`, processed after the commit
     */
    public function __construct(
        public int $created,
        public int $updated,
        public int $errors,
        public int $warnings,
        public array $photoTasks,
    ) {}
}
