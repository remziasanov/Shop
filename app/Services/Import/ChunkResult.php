<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Outcome of {@see RowImporter::importChunk()}. Counts are rows: `errors` is the number of rows with errors,
 * `warnings` the number of warnings of all rows.
 */
final readonly class ChunkResult
{
    /**
     * @param  list<int>  $touchedIds  ids of created and updated products (empty for a dry run)
     * @param  list<array<string, mixed>>  $photoTasks  `{type: 'product', id, refs, mode, row}` to run after the commit
     */
    public function __construct(
        public int $chunk,
        public int $rows,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $errors,
        public int $warnings,
        public int $categoriesCreated,
        public array $touchedIds,
        public array $photoTasks,
    ) {}
}
