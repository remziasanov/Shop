<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\AgeRestriction;
use App\Enums\AttributeType;
use App\Enums\EmptyCellPolicy;
use App\Enums\ImportField;
use App\Enums\ImportIssueLevel;
use App\Enums\ImportMode;
use App\Enums\PhotoMode;
use App\Enums\PriceChangeSource;
use App\Enums\SalesMode;
use App\Exceptions\AttributeException;
use App\Exceptions\CategoryTreeException;
use App\Exceptions\ImportRowException;
use App\Models\Attribute;
use App\Models\Import;
use App\Models\Product;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\AttributeNameResolver;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\AttributeStats;
use App\Services\Catalog\AttributeValueParser;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Pricing\PriceChangeContext;
use App\Services\Stock\StockService;
use App\Settings\CatalogSettings;
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
 *  - characteristics (FL-16): the «Х: …» headers are resolved to the dictionary by the rules (1)-(3) of
 *    {@see AttributeNameResolver}; a missing one is created (not filterable, type by the
 *    values of the chunk) unless the catalog setting forbids it; values are written by AttributeService (a list cell
 *    holds several values separated by «;»); other characteristics of the product are kept, a blank cell keeps or
 *    drops the old value per policy; with no characteristic column mapped they are not touched. Until the migration
 *    from JSON (FL-04) is finished, a product that only has v1 JSON keeps being merged as JSON so that nothing is
 *    lost, the migration converts it later;
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

    /** Dictionary service of the running chunk: a fresh instance per chunk, so a rolled back dry run leaves no stale cache. */
    private ?AttributeService $attributes = null;

    /** @var array<string, Attribute|null> characteristic column name => attribute of the running chunk */
    private array $columnAttributes = [];

    /** @var list<string> */
    private array $columnNames = [];

    private bool $migrated = true;

    /** @var array<int, true> products of the running chunk that already have rows in the tables */
    private array $productsWithRows = [];

    private bool $attributesWritten = false;

    public function __construct(
        private readonly PriceChangeContext $context,
        private readonly HtmlSanitizer $sanitizer,
        private readonly CategoryTree $tree,
        private readonly StockService $stock,
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
                // Photos are not processed in a dry run: rows with unchanged data and photos count as unchanged
                // (created/updated rows are already counted by apply()), so every row lands in exactly one counter.
                $unchangedWithPhotos = count(array_filter($result->photoTasks, fn (array $task): bool => ! empty($task['unchanged'])));
                $result = new ChunkResult($result->chunk, $result->rows, $result->created, $result->updated, $result->unchanged + $unchangedWithPhotos, $result->skipped, $result->errors, $result->warnings, $result->categoriesCreated, [], []);

                DB::rollBack();
                $this->tree->flush();
                $this->persistReport($import, $lines, $issues, $result);
            } else {
                $this->persistReport($import, $lines, $issues, $result);
                DB::commit();

                if ($this->attributesWritten) {
                    CatalogVersion::bump();
                }

                if ($resolver->createdCount() > 0) {
                    $this->tree->flush();
                }
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->tree->flush();

            throw $e;
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function apply(Import $import, ImportOptions $options, int $chunkIndex, array $lines, CategoryPathResolver $resolver, IssueBatch $issues): ChunkResult
    {
        $created = $updated = $unchanged = $skipped = $errors = $warnings = 0;
        $touched = [];
        $tasks = [];

        $products = $this->loadProducts($lines);
        $attributeColumns = $this->attributeColumns($import);
        $this->prepareAttributes($lines, $products, $attributeColumns, $issues, $warnings);

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
                $changed = true;
                $saved = $product === null
                    ? $this->create($values, $fileAttributes, $options, $resolver)
                    : $this->update($product, $values, $fileAttributes, $attributeColumns, $options, $resolver, $changed);
                DB::commit();
            } catch (ImportRowException|CategoryTreeException|AttributeException|UniqueConstraintViolationException|InvalidArgumentException $e) {
                DB::rollBack();
                $resolver->reset();
                $message = $e instanceof ImportRowException || $e instanceof CategoryTreeException || $e instanceof AttributeException ? $e->getMessage() : __('import_process.errors.row_failed');
                $issues->add($row, ImportIssueLevel::Error, null, $message, $sku);
                $errors++;

                continue;
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            $touched[] = $saved->id;

            $photos = $values['photos'] ?? [];
            $hasPhotos = $options->photoMode !== PhotoMode::Skip && $options->mode !== ImportMode::PricesStock && is_array($photos) && $photos !== [];
            $task = $hasPhotos
                ? ['type' => 'product', 'id' => $saved->id, 'refs' => array_values(array_map('strval', (array) $photos)), 'mode' => $options->photoMode->value, 'row' => $row]
                : null;

            if ($product === null) {
                $created++;
            } elseif ($changed) {
                $updated++;
            } elseif ($task !== null) {
                // Data is unchanged: the photo step decides whether the row counts as updated or unchanged (7).
                $task['unchanged'] = true;
            } else {
                $unchanged++;
            }

            if ($task !== null) {
                $tasks[] = $task;
            }
        }

        return new ChunkResult($chunkIndex, count($lines), $created, $updated, $unchanged, $skipped, $errors, $warnings, $resolver->createdCount(), $touched, $tasks);
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
            'unchanged_count' => DB::raw('unchanged_count + '.$result->unchanged),
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

        if (isset($values['age']) && is_int($values['age']) && $values['age'] > 0) {
            $data['age_restriction'] = AgeRestriction::fromAge($values['age']);
        }

        $product = Product::query()->create($data);

        // Stock is written only by StockService (D-046).
        if (isset($values['stock']) && is_int($values['stock'])) {
            $this->stock->setStock($product, $values['stock']);
        }

        $this->writeAttributes($product, $fileAttributes, false);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array{name: string, value: string}>  $fileAttributes
     * @param  list<string>  $attributeColumns
     *
     * @throws ImportRowException
     */
    private function update(Product $product, array $values, array $fileAttributes, array $attributeColumns, ImportOptions $options, CategoryPathResolver $resolver, bool &$changed = true): Product
    {
        $clear = $options->emptyCellPolicy === EmptyCellPolicy::Clear;
        $changes = [];
        $writeAttributes = false;

        // Money: price is required (never cleared); cost and old price follow the policy.
        $this->money($product, $values, 'price', 'base_price', false, $clear, $changes);
        $this->money($product, $values, 'cost_price', 'cost_price', true, $clear, $changes);
        $this->money($product, $values, 'old_price', 'compare_at_price', true, $clear, $changes);

        // Stock goes through StockService after the product is saved (D-046); `false` = leave as is.
        $newStock = false;

        if (array_key_exists('stock', $values)) {
            $stock = $values['stock'];

            if (is_int($stock)) {
                $newStock = max($stock, 0);
            } elseif ($clear) {
                $newStock = null;
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

            // AG-13: 16 / 18 set the level, 0 clears it, a blank cell follows the empty-cell policy.
            if (array_key_exists('age', $values)) {
                $age = $values['age'];

                if (is_int($age)) {
                    $changes['age_restriction'] = $age > 0 ? AgeRestriction::fromAge($age) : null;
                } elseif ($clear) {
                    $changes['age_restriction'] = null;
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
                if ($this->isJsonOnly($product)) {
                    // Not migrated yet: keep the v1 JSON merge, the migration (FL-04) converts the product later.
                    $merged = $this->mergeAttributes($product->specs(), $fileAttributes, $attributeColumns, $clear);

                    if ($merged !== $product->specs()) {
                        $changes['attributes'] = $merged;
                    }
                } else {
                    $writeAttributes = true;
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

        $stockChanged = $newStock !== false && $newStock !== $product->stock_qty;
        $changed = $product->isDirty() || $stockChanged;
        $product->save();

        if ($stockChanged) {
            $this->stock->setStock($product, $newStock);
        }

        if ($writeAttributes && $this->writeAttributes($product, $fileAttributes, $clear)) {
            $changed = true;
        }

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
     * Resolves the characteristic columns of the chunk to dictionary attributes (FL-16): an existing one by the rules
     * (1)-(3), a missing one is created when the file has values for it and the setting allows (type and unit from the
     * values of this chunk, not filterable); otherwise the column is skipped with one warning.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, Product>  $products
     * @param  list<string>  $columns
     */
    private function prepareAttributes(array $lines, array $products, array $columns, IssueBatch $issues, int &$warnings): void
    {
        $this->attributes = app(AttributeService::class);
        $this->columnNames = array_values(array_unique($columns));
        $this->columnAttributes = [];
        $this->productsWithRows = [];
        $this->attributesWritten = false;
        $this->migrated = app(AttributeMigrationState::class)->isMigrated();

        if ($this->columnNames === []) {
            return;
        }

        if (! $this->migrated && $products !== []) {
            $ids = array_values(array_map(fn (Product $product): int => $product->id, $products));
            $this->productsWithRows = array_fill_keys(
                DB::table('product_attribute_values')->whereIn('product_id', $ids)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all(),
                true,
            );
        }

        $samples = [];
        $firstRow = [];

        foreach ($lines as $line) {
            if (! empty($line['errors'])) {
                continue;
            }

            foreach ((array) ($line['attributes'] ?? []) as $attribute) {
                $name = (string) ($attribute['name'] ?? '');
                $samples[$name][] = (string) ($attribute['value'] ?? '');
                $firstRow[$name] ??= (int) ($line['row'] ?? 0);
            }
        }

        $create = app(CatalogSettings::class)->create_attributes_on_import;

        foreach ($this->columnNames as $name) {
            $attribute = $this->attributes->findOrCreateByHeader($name, [], false, false);

            if ($attribute === null && isset($samples[$name])) {
                if ($create) {
                    $attribute = $this->attributes->findOrCreateByHeader($name, AttributeStats::from($samples[$name]), false, true);
                    $this->attributesWritten = true;
                } else {
                    $issues->add($firstRow[$name], ImportIssueLevel::Warning, ColumnMapper::fieldLabel(ImportField::attributeKey($name)), __('admin_attributes.import.skipped', ['name' => $name]));
                    $warnings++;
                }
            }

            $this->columnAttributes[$name] = $attribute;
        }
    }

    /**
     * The product only has v1 JSON characteristics and the migration is not finished (FL-04).
     */
    private function isJsonOnly(Product $product): bool
    {
        return ! $this->migrated && $product->specs() !== [] && ! isset($this->productsWithRows[$product->id]);
    }

    /**
     * Writes the characteristic cells of one row through AttributeService, keeping the characteristics of other
     * columns. A blank cell of a mapped column drops the old value only with the "clear" policy. Returns whether the
     * product's characteristics changed.
     *
     * @param  list<array{name: string, value: string}>  $file  non-blank cells of the row
     *
     * @throws AttributeException
     */
    private function writeAttributes(Product $product, array $file, bool $clear): bool
    {
        if ($this->attributes === null || $this->columnNames === []) {
            return false;
        }

        $cells = [];

        foreach ($file as $cell) {
            $cells[$cell['name']] ??= $cell['value'];
        }

        /** @var array<int, array{attribute_id: int, values: mixed}> $items */
        $items = [];

        foreach ($this->columnNames as $name) {
            $attribute = $this->columnAttributes[$name] ?? null;

            if ($attribute === null || ! isset($cells[$name])) {
                continue;
            }

            $values = $attribute->type === AttributeType::List ? AttributeValueParser::splitList($cells[$name]) : $cells[$name];

            if ($values !== []) {
                $items[$attribute->id] = ['attribute_id' => $attribute->id, 'values' => $values];
            }
        }

        if ($clear) {
            foreach ($this->columnNames as $name) {
                $attribute = $this->columnAttributes[$name] ?? null;

                if ($attribute !== null && ! isset($items[$attribute->id])) {
                    $items[$attribute->id] = ['attribute_id' => $attribute->id, 'values' => []];
                }
            }
        }

        if ($items === []) {
            return false;
        }

        $before = $product->specs();
        $this->attributes->setProductValues($product, array_values($items), false, false);
        $this->attributesWritten = true;

        return $before !== $product->specs();
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

        $mapped = [];

        foreach ($columns as $name) {
            $mapped[mb_strtolower($name, 'UTF-8')] = $name;
        }

        // Existing characteristics keep their order (export -> import round trip must not reorder them);
        // values of mapped ones come from the file, new ones are appended in file column order.
        $result = [];

        foreach ($existing as $attribute) {
            $key = mb_strtolower($attribute['name'], 'UTF-8');

            if (! isset($mapped[$key])) {
                $result[] = $attribute;
            } elseif (isset($fromFile[$key])) {
                $result[] = $fromFile[$key];
                unset($fromFile[$key]);
            } elseif (! $clear) {
                $result[] = $attribute;
            }
        }

        foreach ($mapped as $key => $name) {
            if (isset($fromFile[$key])) {
                $result[] = $fromFile[$key];
            }
        }

        return $result;
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
