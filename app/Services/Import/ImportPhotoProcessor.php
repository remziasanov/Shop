<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportIssueLevel;
use App\Enums\PhotoMode;
use App\Exceptions\ImageFetchDeadlineException;
use App\Exceptions\ImageFetchException;
use App\Exceptions\ImageProcessingException;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Photos of imported products and categories, processed after the data of a chunk is committed (D-033 step 5).
 *
 * A task is `{type: 'product'|'category', id, refs: list<string>, mode: PhotoMode value, row}`. Products:
 *  - a reference whose `source_hash` the product already has is not downloaded again (idempotent re-import);
 *  - "replace": when at least one reference is usable, images whose hash is not among the file's references are
 *    deleted and the rest is ordered as in the cell (first = main); "append": only new ones are added at the end;
 *  - a failing reference becomes a warning, the product is imported anyway.
 * All references are fetched first and only then the catalog is touched; a deadline hit before that changes nothing.
 * The deadline is also checked between the images that are attached: the task is then returned (with the list of
 * references that already produced a warning and a "photos changed" mark) and continued later — attaching is
 * idempotent by `source_hash`, downloads are cached per import.
 *
 * References to this site's own storage (the export writes them, D-040) are never downloaded:
 *  - an image of the same product stays as it is (no change, order kept/applied);
 *  - an image of another product is copied from the local file through {@see ImageProcessor};
 *  - a category cell equal to the category's current image is left alone.
 *
 * A task flagged `unchanged` (the row's data did not change) is counted as updated only when the photos changed,
 * otherwise as unchanged (`imports.updated_count` / `unchanged_count`).
 */
final class ImportPhotoProcessor
{
    /** @var (callable(): void)|null */
    private $heartbeat = null;

    public function __construct(
        private readonly ImageFetcher $fetcher,
        private readonly ImageProcessor $images,
        private readonly OwnMediaLocator $own,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  float  $deadline  microtime(true) by which the step must be finished: no task, download or image
     *                           attachment is started after it, a download never runs past it
     * @param  (callable(): void)|null  $heartbeat  called after every unit of work (keeps the import marked as active)
     * @return list<array<string, mixed>> tasks that were not (completely) processed
     */
    public function run(Import $import, array $tasks, float $deadline, ?callable $heartbeat = null): array
    {
        if ($tasks === []) {
            return [];
        }

        $this->heartbeat = $heartbeat;
        $resolver = new MediaResolver($import, $this->fetcher);
        $issues = new IssueBatch($import->id);
        $pending = [];
        $updated = 0;
        $unchanged = 0;

        try {
            foreach ($tasks as $position => $task) {
                if (microtime(true) >= $deadline) {
                    $pending = array_slice($tasks, $position);

                    break;
                }

                $changed = false;
                $left = ($task['type'] ?? 'product') === 'category'
                    ? $this->category($task, $resolver, $issues, $deadline, $changed)
                    : $this->product($task, $resolver, $issues, $deadline, $changed);

                if ($left !== null) {
                    $pending = [$left, ...array_slice($tasks, $position + 1)];

                    break;
                }

                if (! empty($task['unchanged'])) {
                    $changed ? $updated++ : $unchanged++;
                }

                $this->beat();
            }
        } finally {
            $this->heartbeat = null;

            if (! $issues->isEmpty()) {
                $issues->flush();
            }

            $counters = array_filter([
                'warning_count' => $issues->warningCount(),
                'updated_count' => $updated,
                'unchanged_count' => $unchanged,
            ]);

            foreach ($counters as $column => $count) {
                DB::table('imports')->where('id', $import->id)->update([$column => DB::raw($column.' + '.$count)]);
            }
        }

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>|null null when the task is done, otherwise the task to run later
     */
    private function product(array $task, MediaResolver $resolver, IssueBatch $issues, float $deadline, bool &$changed): ?array
    {
        $product = Product::withTrashed()->find((int) ($task['id'] ?? 0));
        $row = (int) ($task['row'] ?? 0);

        if ($product === null) {
            return null;
        }

        $mode = PhotoMode::tryFrom((string) ($task['mode'] ?? '')) ?? PhotoMode::Replace;
        /** @var list<string> $refs */
        $refs = array_values(array_map('strval', (array) ($task['refs'] ?? [])));

        if ($mode === PhotoMode::Skip || $refs === []) {
            return null;
        }

        $changed = (bool) ($task['photos_changed'] ?? false);
        /** @var array<string, true> $warned references that already produced a warning in an earlier pass */
        $warned = array_fill_keys(array_map('strval', (array) ($task['warned'] ?? [])), true);

        /** @var array<string, int> $idByHash source_hash => image id */
        $idByHash = [];

        foreach ($product->images()->get(['id', 'source_hash']) as $image) {
            if ($image->source_hash !== null) {
                $idByHash[$image->source_hash] = $image->id;
            }
        }

        /** @var list<array{id?: int, hash?: string, ref?: string, path?: string}> $sequence */
        $sequence = [];
        /** @var array<int, true> $keepIds */
        $keepIds = [];
        $seen = [];

        foreach ($refs as $ref) {
            $ownImage = $this->own->productImage($ref);

            if ($ownImage !== null && $ownImage->product_id === $product->id) {
                if (! isset($seen['i:'.$ownImage->id])) {
                    $seen['i:'.$ownImage->id] = true;
                    $sequence[] = ['id' => $ownImage->id];
                    $keepIds[$ownImage->id] = true;
                }

                continue;
            }

            $hash = $ownImage !== null ? sha1('own:'.$ownImage->path_large) : MediaResolver::hashFor($ref);

            if (isset($seen['h:'.$hash])) {
                continue;
            }

            if (isset($idByHash[$hash])) {
                $seen['h:'.$hash] = true;
                $sequence[] = ['id' => $idByHash[$hash]];
                $keepIds[$idByHash[$hash]] = true;

                continue;
            }

            try {
                if ($ownImage !== null) {
                    $path = $this->own->absolutePath($ownImage->path_large)
                        ?? throw new ImageFetchException(__('import_process.media.not_found', ['name' => basename($ownImage->path_large)]));
                } else {
                    if (microtime(true) >= $deadline) {
                        return $this->deferred($task, $warned, $changed);
                    }

                    $path = $resolver->resolve($ref, $deadline);
                    $this->beat();
                }

                $seen['h:'.$hash] = true;
                $sequence[] = ['hash' => $hash, 'ref' => $ref, 'path' => $path];
            } catch (ImageFetchDeadlineException) {
                return $this->deferred($task, $warned, $changed);
            } catch (ImageFetchException $e) {
                $this->warnOnce($issues, $warned, $row, $ref, $e->getMessage());
            }
        }

        if ($mode === PhotoMode::Replace && $sequence !== []) {
            foreach ($product->images()->get() as $image) {
                if (! isset($keepIds[$image->id])) {
                    $image->delete();
                    $changed = true;
                }
            }
        }

        foreach ($sequence as $index => $entry) {
            if (! isset($entry['path'])) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                return $this->deferred($task, $warned, $changed);
            }

            $ref = (string) ($entry['ref'] ?? '');

            try {
                $attached = $this->images->attachToProduct($product, $entry['path'], null, (string) ($entry['hash'] ?? ''));
                $sequence[$index]['id'] = $attached->id;
                $changed = true;
            } catch (ImageProcessingException $e) {
                $this->warnOnce($issues, $warned, $row, $ref, $e->getMessage());
            } catch (Throwable $e) {
                report($e);
                $this->warnOnce($issues, $warned, $row, $ref, __('catalog.errors.image_corrupt'));
            }

            $this->beat();
        }

        $order = [];

        foreach ($sequence as $entry) {
            if (isset($entry['id'])) {
                $order[] = $entry['id'];
            }
        }

        if ($mode === PhotoMode::Replace && $this->reorder($product, $order)) {
            $changed = true;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, true>  $warned
     * @return array<string, mixed>
     */
    private function deferred(array $task, array $warned, bool $changed): array
    {
        $task['warned'] = array_keys($warned);
        $task['photos_changed'] = $changed;

        return $task;
    }

    /**
     * Sort order of the images follows the order of the references in the cell.
     *
     * @param  list<int>  $ids  image ids in the wanted order
     * @return bool whether any sort order changed
     */
    private function reorder(Product $product, array $ids): bool
    {
        $current = $product->images()->get(['id', 'sort_order'])->pluck('sort_order', 'id');
        $changed = false;
        $position = 0;

        foreach ($ids as $id) {
            if (! $current->has($id)) {
                continue;
            }

            if ($current[$id] !== $position) {
                ProductImage::query()->whereKey($id)->update(['sort_order' => $position]);
                $changed = true;
            }

            $position++;
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>|null
     */
    private function category(array $task, MediaResolver $resolver, IssueBatch $issues, float $deadline, bool &$changed): ?array
    {
        $category = Category::query()->find((int) ($task['id'] ?? 0));
        $row = (int) ($task['row'] ?? 0);
        $ref = (string) (((array) ($task['refs'] ?? []))[0] ?? '');

        if ($category === null || $ref === '') {
            return null;
        }

        try {
            $relative = $this->own->relativePath($ref);

            // The cell holds the URL of the current image (an export): nothing to do.
            if ($relative !== null && $relative === $category->image_path) {
                return null;
            }

            $source = $relative !== null ? $this->own->absolutePath($relative) : null;
            $path = $this->images->processCategoryImage($source ?? $resolver->resolve($ref, $deadline), $category);
            $category->update(['image_path' => $path]);
            $changed = true;
        } catch (ImageFetchDeadlineException) {
            return $task;
        } catch (ImageFetchException|ImageProcessingException $e) {
            $this->warn($issues, $row, 'import_process.warnings.category_photo', $ref, $e->getMessage(), CategorySheetImporter::columnPrefix());
        } catch (Throwable $e) {
            report($e);
            $this->warn($issues, $row, 'import_process.warnings.category_photo', $ref, __('catalog.errors.image_corrupt'), CategorySheetImporter::columnPrefix());
        }

        return null;
    }

    private function beat(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }

    /**
     * @param  array<string, true>  $warned
     */
    private function warnOnce(IssueBatch $issues, array &$warned, int $row, string $ref, string $message): void
    {
        if (isset($warned[$ref])) {
            return;
        }

        $warned[$ref] = true;
        $this->warn($issues, $row, 'import_process.warnings.photo', $ref, $message);
    }

    private function warn(IssueBatch $issues, int $row, string $key, string $ref, string $message, ?string $prefix = null): void
    {
        $column = __('import_process.photo_column');
        $issues->add($row, ImportIssueLevel::Warning, $prefix !== null ? $prefix.' / '.$column : $column, __($key, ['ref' => $ref, 'message' => $message]), $ref);
    }
}
