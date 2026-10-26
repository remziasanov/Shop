<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportField;
use App\Enums\ImportIssueLevel;
use App\Enums\ImportMode;
use App\Enums\PhotoMode;
use App\Enums\PriceChangeSource;
use App\Enums\SalesMode;
use App\Exceptions\CategoryTreeException;
use App\Exceptions\ImportRowException;
use App\Models\Import;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Pricing\PriceChangeContext;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Applies one chunk of the staged rows (`rows.jsonl`) to the catalog in a single DB transaction (IM-09, D-033).
 *
 *  - rows with errors are skipped (their errors and every row's warnings go to `import_issues`);
 *  - products are matched by SKU, soft-deleted ones are restored (D-038); modes: create_update / full_sync
 *    (identical here, deactivation happens in {@see ImportRunner::finish()}) and prices_stock (only price, cost,
 *    old price and stock of existing products, unknown SKUs are skipped);
 *  - blank cells follow the empty-cell policy: "keep" leaves the field, "clear" empties nullable fields
 *    (brand, short description, description, cost, old price, stock, sales mode, meta); required ones never;
 *  - characteristics: when characteristic columns are mapped, the product gets the file's non-empty values in
 *    column order, existing characteristics of unmapped names are kept, blank cells keep or drop the old value
 *    per policy; with no characteristic column mapped they are not touched;
 *  - price changes run inside a {@see PriceChangeContext} (source "import", import id, starter);
 *  - every row has its own savepoint: a row that fails with a domain error is reported and the chunk goes on;
 *  - issues and the import counters (rows_processed, created/updated/skipped/error/warning counts, chunks_done)
 *    are written inside the same transaction, so a chunk is applied exactly once;
 *  - dry run: everything runs, then the transaction is rolled back and only issues and counters are stored.
 *
 * Photos are not handled here: tasks are returned for {@see ImportPhotoProcessor}.
 */
final class RowImporter
{
    private const CLEARABLE_TEXT = ['brand', 'short_description', 'meta_title', 'meta_description'];

    private const REQUIRED_TEXT = ['name', 'unit'];

    public function __construct(
        private readonly PriceChangeContext $context,
        private readonly HtmlSanitizer $sanitizer,
        private readonly CategoryTree $tree,
    ) {}

    public function importChunk(Import $import, int $chunkIndex): ChunkResult
    {
        $options = $import->importOptions();
        $lines = $this->readChunk($import, $chunkIndex);
        $resolver = new CategoryPathResolver;
        $issues = new IssueBatch($import->id);

        DB::beginTransaction();

        try {
            $result = $this->context->run(
                PriceChangeSource::Import,
                $import->id,
                fn (): ChunkResult => $this->apply($import, $options, $chunkIndex, $lines, $resolver, $issues),
                $import->user_id,
            );

            if ($options->dryRun) {
                DB::rollBack();
                $this->tree->flush();
                $this->persistReport($import, $lines, $issues, $result);
            } else {
                $this->persistReport($import, $lines, $issues, $result);
                DB::commit();

                if ($resolver->createdCount() > 0) {
                    $this->tree->flush();
                }
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->tree->flush();

            throw $e;
        }

        return $options->dryRun
            ? new ChunkResult($result->chunk, $result->rows, $result->created, $result->updated, $result->skipped, $result->errors, $result->warnings, $result->categoriesCreated, [], [])
            : $result;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function apply(Import $import, ImportOptions $options, int $chunkIndex, array $lines, CategoryPathResolver $resolver, IssueBatch $issues): ChunkResult
    {
        $created = $updated = $skipped = $errors = $warnings = 0;
        $touched = [];
        $tasks = [];

        $products = $this->loadProducts($lines);
        $attributeColumns = $this->attributeColumns($import);

        foreach ($lines as $line) {
            $row = (int) ($line['row'] ?? 0);

            foreach ((array) ($line['warnings'] ?? []) as $warning) {
                $issues->addStaged($row, ImportIssueLevel::Warning, (array) $warning);
                $warnings++;
            }

            $stagedErrors = (array) ($line['errors'] ?? []);

            if ($stagedErrors !== []) {
                foreach ($stagedErrors as $error) {
                    $issues->addStaged($row, ImportIssueLevel::Error, (array) $error);
                }

                $errors++;

                continue;
            }

            /** @var array<string, mixed> $values */
            $values = (array) ($line['values'] ?? []);
            /** @var list<array{name: string, value: string}> $fileAttributes */
            $fileAttributes = array_values((array) ($line['attributes'] ?? []));
            $sku = (string) ($values['sku'] ?? '');
            $product = $products[mb_strtolower($sku, 'UTF-8')] ?? null;

            if ($product === null && $options->mode === ImportMode::PricesStock) {
                $skipped++;

                continue;
            }

            try {
                DB::beginTransaction();
                $saved = $product === null
                    ? $this->create($values, $fileAttributes, $options, $resolver)
                    : $this->update($product, $values, $fileAttributes, $attributeColumns, $options, $resolver);
                DB::commit();
            } catch (ImportRowException|CategoryTreeException|UniqueConstraintViolationException|InvalidArgumentException $e) {
                DB::rollBack();
                $message = $e instanceof ImportRowException || $e instanceof CategoryTreeException ? $e->getMessage() : __('import_process.errors.row_failed');
                $issues->add($row, ImportIssueLevel::Error, null, $message, $sku);
                $errors++;

                continue;
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            if ($product === null) {
                $created++;
            } else {
                $updated++;
            }

            $touched[] = $saved->id;

            $photos = $values['photos'] ?? [];

            if ($options->photoMode !== PhotoMode::Skip && $options->mode !== ImportMode::PricesStock && is_array($photos) && $photos !== []) {
                $tasks[] = ['type' => 'product', 'id' => $saved->id, 'refs' => array_values(array_map('strval', $photos)), 'mode' => $options->photoMode->value, 'row' => $row];
            }
        }

        return new ChunkResult($chunkIndex, count($lines), $created, $updated, $skipped, $errors, $warnings, $resolver->createdCount(), $touched, $tasks);
    }

    /**
     * Write the issues and counters (inside the open transaction for a real run, after the rollback for a dry run).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function persistReport(Import $import, array $lines, IssueBatch $issues, ChunkResult $result): void
    {
        if ($lines !== []) {
            $numbers = array_map(fn (array $line): int => (int) ($line['row'] ?? 0), $lines);

            // Idempotent: a re-run of the chunk replaces its issues (the categories sheet keeps its own).
            $import->issues()
                ->whereBetween('row_number', [min($numbers), max($numbers)])
                ->where(fn ($query) => $query->whereNull('column')->orWhere('column', 'not like', CategorySheetImporter::columnPrefix().'%'))
                ->delete();
        }

        $issues->flush();

        DB::table('imports')->where('id', $import->id)->update([
            'rows_processed' => DB::raw('rows_processed + '.$result->rows),
            'created_count' => DB::raw('created_count + '.$result->created),
            'updated_count' => DB::raw('updated_count + '.$result->updated),
            'skipped_count' => DB::raw('skipped_count + '.$result->skipped),
            'error_count' => DB::raw('error_count + '.$result->errors),
            'warning_count' => DB::raw('warning_count + '.$result->warnings),
            'chunks_done' => DB::raw('chunks_done + 1'),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array{name: string, value: string}>  $fileAttributes
     *
     * @throws ImportRowException
     */
    private function create(array $values, array $fileAttributes, ImportOptions $options, CategoryPathResolver $resolver): Product
    {
        foreach ([ImportField::Name, ImportField::Price] as $field) {
            if (($values[$field->value] ?? null) === null) {
                throw new ImportRowException(__('import.errors.required_new', ['field' => $field->label()]));
            }
        }

        $path = $this->path($values);

        if ($path === []) {
            throw new ImportRowException(__('import.errors.required_new', ['field' => ImportField::Category->label()]));
        }

        $categoryId = $this->categoryId($path, $options, $resolver);

        $data = [
            'category_id' => $categoryId,
            'sku' => (string) $values['sku'],
            'name' => (string) $values['name'],
            'base_price' => Money::toRubles((int) $values['price']),
            'is_active' => ($values['active'] ?? null) ?? true,
        ];

        foreach (['cost_price', 'old_price'] as $key) {
            if (isset($values[$key]) && is_int($values[$key])) {
                $data[$key === 'old_price' ? 'compare_at_price' : 'cost_price'] = Money::toRubles($values[$key]);
            }
        }

        if (isset($values['stock']) && is_int($values['stock'])) {
            $data['stock_qty'] = $values['stock'];
        }

        foreach (['unit', 'brand', 'short_description', 'meta_title', 'meta_description'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) {
                $data[$key] = $values[$key];
            }
        }

        if (isset($values['description']) && is_string($values['description'])) {
            $description = $this->sanitizer->sanitize($values['description']);
            $data['description'] = $description === '' ? null : $description;
        }

        foreach (['featured' => 'is_featured', 'new' => 'is_new', 'price_on_request' => 'price_on_request'] as $key => $column) {
            if (isset($values[$key]) && is_bool($values[$key])) {
                $data[$column] = $values[$key];
            }
        }

        if (isset($values['sort']) && is_int($values['sort'])) {
            $data['sort_order'] = $values['sort'];
        }

        $mode = $this->salesMode($values['sales_mode'] ?? null);

        if ($mode !== null) {
            $data['sales_mode'] = $mode;
        }

        $attributes = $this->mergeAttributes([], $fileAttributes, $this->fileNames($fileAttributes), false);

        if ($attributes !== []) {
            $data['attributes'] = $attributes;
        }

        return Product::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array{name: string, value: string}>  $fileAttributes
     * @param  list<string>  $attributeColumns
     *
     * @throws ImportRowException
     */
    private function update(Product $product, array $values, array $fileAttributes, array $attributeColumns, ImportOptions $options, CategoryPathResolver $resolver): Product
    {
        $clear = $options->emptyCellPolicy === EmptyCellPolicy::Clear;
        $changes = [];

        // Money: price is required (never cleared); cost and old price follow the policy.
        $this->money($product, $values, 'price', 'base_price', false, $clear, $changes);
        $this->money($product, $values, 'cost_price', 'cost_price', true, $clear, $changes);
        $this->money($product, $values, 'old_price', 'compare_at_price', true, $clear, $changes);

        if (array_key_exists('stock', $values)) {
            $stock = $values['stock'];

            if (is_int($stock)) {
                $changes['stock_qty'] = $stock;
            } elseif ($clear) {
                $changes['stock_qty'] = null;
            }
        }

        if ($options->mode !== ImportMode::PricesStock) {
            foreach ([...self::REQUIRED_TEXT, ...self::CLEARABLE_TEXT] as $key) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $text = $values[$key];

                if (is_string($text) && $text !== '') {
                    $changes[$key] = $text;
                } elseif ($clear && in_array($key, self::CLEARABLE_TEXT, true)) {
                    $changes[$key] = null;
                }
            }

            if (array_key_exists('description', $values)) {
                $description = $values['description'];

                if (is_string($description) && $description !== '') {
                    $sanitized = $this->sanitizer->sanitize($description);
                    $changes['description'] = $sanitized === '' ? null : $sanitized;
                } elseif ($clear) {
                    $changes['description'] = null;
                }
            }

            foreach (['active' => 'is_active', 'featured' => 'is_featured', 'new' => 'is_new', 'price_on_request' => 'price_on_request'] as $key => $column) {
                if (isset($values[$key]) && is_bool($values[$key])) {
                    $changes[$column] = $values[$key];
                }
            }

            if (isset($values['sort']) && is_int($values['sort'])) {
                $changes['sort_order'] = $values['sort'];
            }

            if (array_key_exists('sales_mode', $values)) {
                $raw = $values['sales_mode'];

                if ($raw === null) {
                    if ($clear) {
                        $changes['sales_mode'] = null;
                    }
                } else {
                    $changes['sales_mode'] = $this->salesMode($raw);
                }
            }

            $path = $this->path($values);

            if ($path !== []) {
                $categoryId = $this->categoryId($path, $options, $resolver);

                if ($categoryId !== $product->category_id) {
                    $changes['category_id'] = $categoryId;
                }
            }

            if ($attributeColumns !== []) {
                $merged = $this->mergeAttributes($product->specs(), $fileAttributes, $attributeColumns, $clear);

                if ($merged !== $product->specs()) {
                    $changes['attributes'] = $merged;
                }
            }
        }

        foreach ($changes as $column => $value) {
            $product->setAttribute($column, $value);
        }

        if ($product->trashed()) {
            // D-038: a row for a soft-deleted SKU restores the product (the observer re-prices it).
            $product->deleted_at = null;
        }

        $product->save();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $changes
     */
    private function money(Product $product, array $values, string $key, string $column, bool $clearable, bool $clear, array &$changes): void
    {
        if (! array_key_exists($key, $values)) {
            return;
        }

        $kopecks = $values[$key];
        $current = $product->getAttribute($column);

        if (is_int($kopecks)) {
            if ($current === null || Money::fromRubles((string) $current) !== $kopecks) {
                $changes[$column] = Money::toRubles($kopecks);
            }
        } elseif ($clearable && $clear && $current !== null) {
            $changes[$column] = null;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function path(array $values): array
    {
        $path = $values['category'] ?? [];

        return is_array($path) ? array_values(array_map('strval', $path)) : [];
    }

    /**
     * @param  list<string>  $path
     *
     * @throws ImportRowException
     */
    private function categoryId(array $path, ImportOptions $options, CategoryPathResolver $resolver): int
    {
        $id = $resolver->resolve($path, $options->createCategories);

        if ($id === null) {
            throw new ImportRowException(__('import_process.errors.category_not_found', ['path' => implode(' / ', $path)]));
        }

        return $id;
    }

    private function salesMode(mixed $raw): ?SalesMode
    {
        return is_string($raw) && $raw !== ValueParser::SALES_MODE_DEFAULT ? SalesMode::tryFrom($raw) : null;
    }

    /**
     * @param  list<array{name: string, value: string}>  $existing
     * @param  list<array{name: string, value: string}>  $file  non-empty characteristics of the row
     * @param  list<string>  $columns  names of the mapped characteristic columns, in column order
     * @return list<array{name: string, value: string}>
     */
    private function mergeAttributes(array $existing, array $file, array $columns, bool $clear): array
    {
        $fromFile = [];

        foreach ($file as $attribute) {
            $fromFile[mb_strtolower($attribute['name'], 'UTF-8')] ??= ['name' => $attribute['name'], 'value' => $attribute['value']];
        }

        $old = [];

        foreach ($existing as $attribute) {
            $old[mb_strtolower($attribute['name'], 'UTF-8')] ??= $attribute;
        }

        $result = [];
        $mapped = [];

        foreach ($columns as $name) {
            $key = mb_strtolower($name, 'UTF-8');

            if (isset($mapped[$key])) {
                continue;
            }

            $mapped[$key] = true;

            if (isset($fromFile[$key])) {
                $result[] = $fromFile[$key];
            } elseif (! $clear && isset($old[$key])) {
                $result[] = $old[$key];
            }
        }

        foreach ($existing as $attribute) {
            if (! isset($mapped[mb_strtolower($attribute['name'], 'UTF-8')])) {
                $result[] = $attribute;
            }
        }

        return $result;
    }

    /**
     * @param  list<array{name: string, value: string}>  $attributes
     * @return list<string>
     */
    private function fileNames(array $attributes): array
    {
        return array_map(fn (array $attribute): string => $attribute['name'], $attributes);
    }

    /**
     * Names of the mapped characteristic columns in column order.
     *
     * @return list<string>
     */
    private function attributeColumns(Import $import): array
    {
        $names = [];

        foreach ($import->mapping ?? [] as $field) {
            $name = ImportField::attributeName(is_string($field) ? $field : null);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Existing products of the chunk by lower-cased SKU (soft-deleted included).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, Product>
     */
    private function loadProducts(array $lines): array
    {
        $skus = [];

        foreach ($lines as $line) {
            $sku = $line['values']['sku'] ?? null;

            if (is_string($sku) && $sku !== '') {
                $skus[] = $sku;
            }
        }

        $found = [];

        foreach (array_chunk(array_values(array_unique($skus)), 500) as $batch) {
            foreach (Product::withTrashed()->whereIn('sku', $batch)->get() as $product) {
                $found[mb_strtolower($product->sku, 'UTF-8')] = $product;
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readChunk(Import $import, int $chunkIndex): array
    {
        $offsets = $import->chunk_offsets ?? [];

        if (! isset($offsets[$chunkIndex]) || $import->staging_path === null) {
            throw new RuntimeException('Import chunk '.$chunkIndex.' is not staged.');
        }

        $size = max(1, $import->chunk_size);
        $count = min($size, $import->rows_total - $chunkIndex * $size);
        $handle = fopen(Storage::disk('local')->path($import->staging_path), 'rb');

        if ($handle === false) {
            throw new RuntimeException('The staged rows of import '.$import->id.' cannot be read.');
        }

        $lines = [];

        try {
            fseek($handle, (int) $offsets[$chunkIndex]);

            while (count($lines) < $count && ($text = fgets($handle)) !== false) {
                $text = trim($text);

                if ($text !== '') {
                    $lines[] = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                }
            }
        } finally {
            fclose($handle);
        }

        return $lines;
    }
}
