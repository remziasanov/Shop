<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\CategorySheetField;
use App\Enums\ImportStatus;
use App\Exceptions\ImportFileException;
use App\Models\Import;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Single streaming pass over an import file (D-033 step 3, IM-06).
 *
 * What it does for an {@see Import} (file on the private `local` disk, `mapping` and options set):
 *  - reads the product sheet row by row and writes normalized rows to `imports/{id}/rows.jsonl`
 *    (one JSON object per line: `row`, `values`, `attributes`, `errors`, `warnings`, `action`),
 *    remembering the byte offset of every `chunk_size`-th row in `chunk_offsets`;
 *  - checks which SKUs already exist (batches of 500, soft-deleted products count as existing: the
 *    importer must restore them) and counts create / update / skip / errors for the summary;
 *  - keeps the first 20 rows (values, raw cells, per-column errors) for the preview;
 *  - stages the optional «Категории» sheet to `imports/{id}/categories.jsonl`
 *    (`row`, `path`, optional `description|sort|active|sales_mode|photo|meta_title|meta_description`, `errors`);
 *  - stores `rows_total`, `chunks_total`, `chunk_offsets`, `analysis` and sets the status to `analyzed`.
 *
 * It writes nothing to the catalog and no `import_issues` rows (the importer records issues of each staged
 * line while processing its chunk, so they are never duplicated). Memory: one batch of rows plus the map of
 * seen SKUs. Rows with errors are staged too (they count in `rows_total` and are skipped by the importer).
 *
 * The `analysis` JSON: `headers`, `mapping_warnings`, `summary` {create, update, skip, errors, warnings,
 * restored, rows_total, categories_rows, categories_errors} and `preview` (list of
 * {row, values, raw, errors, warnings, action}). Error entries are {field, column, message, value}.
 */
final class ImportAnalyzer
{
    public const PREVIEW_ROWS = 20;

    /** Lifetime of the analysis lock; a crashed analysis blocks a new one at most this long. */
    public const LOCK_SECONDS = 120;

    public const LOOKUP_BATCH = 500;

    /** Longest stored raw value in an issue, in characters. */
    public const MAX_ISSUE_VALUE = 500;

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly ImportReader $reader,
        private readonly ColumnMapper $mapper,
        private readonly RowValidator $validator,
    ) {}

    /**
     * Analyze the import. On failure the import is marked `failed` (user-facing message) and the exception is
     * rethrown so the caller can log it.
     *
     * Only one analysis runs at a time (the queue job and «Проверить сейчас» can start together, D-043): it is
     * claimed with an atomic cache lock and a status check under it (`uploaded|analyzing` → `analyzing`; a conditional
     * UPDATE would report 0 affected rows on MySQL when nothing changes). A caller that loses the lock, or finds the
     * import already analyzed or started, does nothing and gets the import back.
     *
     * @throws Throwable
     */
    public function analyze(Import $import): Import
    {
        $lock = Cache::lock('import:'.$import->id.':analyze', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return $import->refresh();
        }

        try {
            // Read under the lock: an analysis that finished (or an import that already started) a moment ago is final.
            $current = DB::table('imports')->where('id', $import->id)->value('status');

            if (! in_array($current, [ImportStatus::Uploaded->value, ImportStatus::Analyzing->value], true)) {
                return $import->refresh();
            }

            DB::table('imports')->where('id', $import->id)
                ->update(['status' => ImportStatus::Analyzing->value, 'failure_message' => null, 'last_activity_at' => now()]);

            $import->refresh();

            return $this->analyzeClaimed($import);
        } finally {
            $lock->release();
        }
    }

    private function analyzeClaimed(Import $import): Import
    {
        try {
            $this->run($import);
        } catch (Throwable $e) {
            $import->forceFill([
                'status' => ImportStatus::Failed,
                'failure_message' => $e instanceof ImportFileException ? $e->getMessage() : __('import.errors.analysis_failed'),
                'finished_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            throw $e;
        }

        return $import;
    }

    private function run(Import $import): void
    {
        $disk = Storage::disk('local');
        $options = $import->importOptions();
        $source = $disk->path($import->file_path);
        $directory = 'imports/'.$import->id;

        $disk->makeDirectory($directory);

        $stagingPath = $directory.'/rows.jsonl';
        $categoriesPath = $directory.'/categories.jsonl';
        $disk->delete([$stagingPath, $categoriesPath]);

        $sheets = $this->reader->sheets($source);
        $sheet = $import->sheet ?? $this->reader->guessProductSheet($sheets);

        // Categories first: cheap, and the sheet is small.
        $categoryStats = ['rows' => 0, 'errors' => 0];
        $hasCategoriesSheet = $sheet !== CategorySheetField::SHEET_NAME && in_array(CategorySheetField::SHEET_NAME, $sheets, true);

        if ($hasCategoriesSheet) {
            $categoryStats = $this->stageCategories($source, $disk->path($categoriesPath), $options);
        }

        $stats = $this->stageProducts($import, $source, $sheet, $disk->path($stagingPath), $options);

        $analysis = $import->analysis ?? [];
        $analysis['headers'] = $stats['headers'];
        $analysis['mapping_warnings'] = $stats['mapping_warnings'];
        $analysis['summary'] = [
            'create' => $stats['create'],
            'update' => $stats['update'],
            'skip' => $stats['skip'],
            'errors' => $stats['errors'],
            'warnings' => $stats['warnings'],
            'restored' => $stats['restored'],
            'rows_total' => $stats['rows'],
            'categories_rows' => $categoryStats['rows'],
            'categories_errors' => $categoryStats['errors'],
        ];
        $analysis['preview'] = $stats['preview'];

        $import->forceFill([
            'sheet' => $import->sheet ?? $sheet,
            'mapping' => $stats['mapping'],
            'staging_path' => $stagingPath,
            'categories_staging_path' => $hasCategoriesSheet && $categoryStats['rows'] > 0 ? $categoriesPath : null,
            'rows_total' => $stats['rows'],
            'rows_processed' => 0,
            'chunks_total' => count($stats['offsets']),
            'next_chunk' => 0,
            'chunks_done' => 0,
            'chunk_offsets' => $stats['offsets'],
            'analysis' => $analysis,
            'status' => ImportStatus::Analyzed,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * @return array{rows: int, errors: int}
     */
    private function stageCategories(string $source, string $target, ImportOptions $options): array
    {
        $out = $this->openForWrite($target);
        $mapping = null;
        $rows = 0;
        $errors = 0;
        try {
            foreach ($this->reader->open($source, CategorySheetField::SHEET_NAME) as $number => $cells) {
                if ($mapping === null) {
                    $mapping = $this->mapper->autoMapCategories($cells);

                    continue;
                }

                $result = $this->validator->validateCategory($this->mapper->mapCategoryRow($cells, $mapping), $options->categorySeparator);
                $line = ['row' => $number] + $result['values'];

                $line['errors'] = array_map(fn (RowIssue $issue): array => $this->issueToArray($issue), $result['errors']);
                $errors += $result['errors'] === [] ? 0 : 1;
                $rows++;

                fwrite($out, json_encode($line, self::JSON_FLAGS)."\n");
            }
        } finally {
            fclose($out);
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @return array{rows: int, create: int, update: int, skip: int, errors: int, warnings: int, restored: int, headers: list<string|null>, mapping: array<int, string|null>, mapping_warnings: list<string>, offsets: list<int>, preview: list<array<string, mixed>>}
     */
    private function stageProducts(Import $import, string $source, ?string $sheet, string $target, ImportOptions $options): array
    {
        $out = $this->openForWrite($target);

        $stats = [
            'rows' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'errors' => 0, 'warnings' => 0, 'restored' => 0,
            'headers' => [], 'mapping' => [], 'mapping_warnings' => [], 'offsets' => [], 'preview' => [],
        ];

        $mapping = null;
        $headers = [];
        $seenSkus = [];
        $buffer = [];
        $offset = 0;
        $chunkSize = max(1, $import->chunk_size);

        try {
            foreach ($this->reader->open($source, $sheet) as $number => $cells) {
                if ($mapping === null) {
                    $headers = $cells;
                    $given = $import->mapping;
                    $result = $given === null || $given === []
                        ? $this->mapper->autoMapWithWarnings($headers)
                        : $this->mapper->dedupe($given, $headers);
                    $mapping = $result->mapping;

                    // The mapping may be wider than the header row (blank trailing headers): keep indices.
                    $stats['headers'] = $headers;
                    $stats['mapping'] = $mapping;
                    $stats['mapping_warnings'] = $result->warnings($headers);

                    if (! in_array('sku', $mapping, true)) {
                        throw new ImportFileException(__('import.errors.no_sku_column'));
                    }

                    continue;
                }

                $buffer[] = ['row' => $number, 'mapped' => $this->mapper->mapRow($cells, $mapping), 'columns' => $mapping];

                if (count($buffer) >= self::LOOKUP_BATCH) {
                    $this->flushBatch($buffer, $headers, $options, $chunkSize, $seenSkus, $stats, $offset, $out);
                    $buffer = [];
                }
            }

            if ($mapping === null) {
                throw new ImportFileException(__('import.errors.empty_file'));
            }

            $this->flushBatch($buffer, $headers, $options, $chunkSize, $seenSkus, $stats, $offset, $out);
        } finally {
            fclose($out);
        }

        return $stats;
    }

    /**
     * Validate and stage one buffered batch of rows.
     *
     * @param  list<array{row: int, mapped: MappedRow, columns: array<int, string|null>}>  $buffer
     * @param  list<string|null>  $headers
     * @param  array<string, int>  $seenSkus
     * @param  array<string, mixed>  $stats
     * @param  resource  $out
     */
    private function flushBatch(array $buffer, array $headers, ImportOptions $options, int $chunkSize, array &$seenSkus, array &$stats, int &$offset, $out): void
    {
        if ($buffer === []) {
            return;
        }

        $existing = $this->existingSkus(array_values(array_filter(array_map(fn (array $item): ?string => $this->skuOf($item['mapped']), $buffer))));

        foreach ($buffer as $item) {
            $mapped = $item['mapped'];
            $sku = $this->skuOf($mapped);
            $state = $sku === null ? null : ($existing[mb_strtolower($sku, 'UTF-8')] ?? null);

            $result = $this->validator->validate(
                $mapped,
                $options->mode,
                $state !== null,
                $seenSkus,
                $options->categorySeparator,
                $item['row'],
            );

            $errors = array_map(fn (RowIssue $issue): array => $this->issueToArray($this->withHeader($issue, $headers, $item['columns'])), $result->errors);
            $warnings = array_map(fn (RowIssue $issue): array => $this->issueToArray($this->withHeader($issue, $headers, $item['columns'])), $result->warnings);

            $line = json_encode([
                'row' => $item['row'],
                'values' => $result->values,
                'attributes' => $result->attributes,
                'errors' => $errors,
                'warnings' => $warnings,
                'action' => $result->action,
            ], self::JSON_FLAGS)."\n";

            if ($stats['rows'] % $chunkSize === 0) {
                $stats['offsets'][] = $offset;
            }

            fwrite($out, $line);
            $offset += strlen($line);
            $stats['rows']++;

            match ($result->action) {
                RowValidationResult::ACTION_CREATE => $stats['create']++,
                RowValidationResult::ACTION_UPDATE => $stats['update']++,
                RowValidationResult::ACTION_SKIP => $stats['skip']++,
                default => $stats['errors']++,
            };

            if ($state === true && $result->action === RowValidationResult::ACTION_UPDATE) {
                $stats['restored']++;
            }

            $stats['warnings'] += count($warnings);

            if (count($stats['preview']) < self::PREVIEW_ROWS) {
                $stats['preview'][] = [
                    'row' => $item['row'],
                    'values' => $result->values,
                    'attributes' => $result->attributes,
                    'raw' => $mapped->values,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'action' => $result->action,
                ];
            }
        }
    }

    /**
     * Existing SKUs of a batch: lower-cased SKU => true when the product is soft-deleted, false when active.
     *
     * @param  array<int, string>  $skus
     * @return array<string, bool>
     */
    private function existingSkus(array $skus): array
    {
        $skus = array_values(array_unique($skus));

        if ($skus === []) {
            return [];
        }

        $found = [];

        $rows = Product::withTrashed()->whereIn('sku', $skus)->get(['sku', 'deleted_at']);

        foreach ($rows as $product) {
            $found[mb_strtolower($product->sku, 'UTF-8')] = $product->deleted_at !== null;
        }

        return $found;
    }

    private function skuOf(MappedRow $row): ?string
    {
        $sku = $row->get('sku');
        $sku = $sku === null ? null : trim($sku);

        return $sku === null || $sku === '' ? null : $sku;
    }

    /**
     * Replace the field label with the real file header of the mapped column.
     *
     * @param  list<string|null>  $headers
     * @param  array<int, string|null>  $mapping
     */
    private function withHeader(RowIssue $issue, array $headers, array $mapping): RowIssue
    {
        if ($issue->field === '') {
            return $issue;
        }

        $index = array_search($issue->field, $mapping, true);

        if ($index !== false && ($headers[$index] ?? null) !== null) {
            return $issue->withColumn((string) $headers[$index]);
        }

        return $issue;
    }

    /**
     * @return array{field: string, column: string, message: string, value: string|null}
     */
    private function issueToArray(RowIssue $issue): array
    {
        $array = $issue->toArray();

        if ($array['value'] !== null && mb_strlen($array['value']) > self::MAX_ISSUE_VALUE) {
            $array['value'] = mb_substr($array['value'], 0, self::MAX_ISSUE_VALUE);
        }

        return $array;
    }

    /**
     * @return resource
     */
    private function openForWrite(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new ImportFileException(__('import.errors.file_unreadable'));
        }

        return $handle;
    }
}
