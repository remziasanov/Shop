<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportIssueLevel;
use App\Enums\PhotoMode;
use App\Exceptions\ImageFetchException;
use App\Exceptions\ImageProcessingException;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;
use Throwable;

/**
 * Photos of imported products and categories, processed after the data of a chunk is committed (D-033 step 5).
 *
 * A task is `{type: 'product'|'category', id, refs: list<string>, mode: PhotoMode value, row}`. Products:
 *  - a reference whose `source_hash` the product already has is not downloaded again (idempotent re-import);
 *  - "replace": when at least one reference is usable, images whose hash is not among the file's references are
 *    deleted and the rest is ordered as in the cell (first = main); "append": only new ones are added at the end;
 *  - a failing reference becomes a warning, the product is imported anyway.
 * All references are fetched first and only then the catalog is touched, so a deadline hit mid-task changes
 * nothing; such a task (and all after it) is returned to be run later.
 */
final class ImportPhotoProcessor
{
    public function __construct(
        private readonly ImageFetcher $fetcher,
        private readonly ImageProcessor $images,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  float  $deadline  microtime(true) after which no new task is started
     * @return list<array<string, mixed>> tasks that were not processed
     */
    public function run(Import $import, array $tasks, float $deadline): array
    {
        if ($tasks === []) {
            return [];
        }

        $resolver = new MediaResolver($import, $this->fetcher);
        $issues = new IssueBatch($import->id);
        $pending = [];

        foreach ($tasks as $position => $task) {
            if (microtime(true) >= $deadline) {
                $pending = array_slice($tasks, $position);

                break;
            }

            $done = ($task['type'] ?? 'product') === 'category'
                ? $this->category($task, $resolver, $issues)
                : $this->product($task, $resolver, $issues, $deadline);

            if (! $done) {
                $pending = array_slice($tasks, $position);

                break;
            }
        }

        if (! $issues->isEmpty()) {
            $issues->flush();
            Import::query()->whereKey($import->id)->increment('warning_count', $issues->warningCount());
        }

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return bool false when the deadline stopped the task before anything was changed
     */
    private function product(array $task, MediaResolver $resolver, IssueBatch $issues, float $deadline): bool
    {
        $product = Product::withTrashed()->find((int) ($task['id'] ?? 0));
        $row = (int) ($task['row'] ?? 0);

        if ($product === null) {
            return true;
        }

        $mode = PhotoMode::tryFrom((string) ($task['mode'] ?? '')) ?? PhotoMode::Replace;
        /** @var list<string> $refs */
        $refs = array_values(array_map('strval', (array) ($task['refs'] ?? [])));

        if ($mode === PhotoMode::Skip || $refs === []) {
            return true;
        }

        /** @var array<string, int> $existing source_hash => image id */
        $existing = [];

        foreach ($product->images()->get(['id', 'source_hash']) as $image) {
            if ($image->source_hash !== null) {
                $existing[$image->source_hash] = $image->id;
            }
        }

        $hashes = [];
        $files = [];

        foreach ($refs as $ref) {
            $hash = MediaResolver::hashFor($ref);

            if (in_array($hash, $hashes, true)) {
                continue;
            }

            if (isset($existing[$hash])) {
                $hashes[] = $hash;

                continue;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            try {
                $files[$hash] = ['ref' => $ref, 'path' => $resolver->resolve($ref)];
                $hashes[] = $hash;
            } catch (ImageFetchException $e) {
                $this->warn($issues, $row, 'import_process.warnings.photo', $ref, $e->getMessage());
            }
        }

        if ($mode === PhotoMode::Replace && $hashes !== []) {
            foreach ($product->images()->get() as $image) {
                if ($image->source_hash === null || ! in_array($image->source_hash, $hashes, true)) {
                    $image->delete();
                    unset($existing[(string) $image->source_hash]);
                }
            }
        }

        foreach ($files as $hash => $file) {
            try {
                $this->images->attachToProduct($product, $file['path'], null, (string) $hash);
            } catch (ImageProcessingException $e) {
                $this->warn($issues, $row, 'import_process.warnings.photo', $file['ref'], $e->getMessage());
            } catch (Throwable $e) {
                report($e);
                $this->warn($issues, $row, 'import_process.warnings.photo', $file['ref'], __('catalog.errors.image_corrupt'));
            }
        }

        if ($mode === PhotoMode::Replace) {
            $this->reorder($product, $hashes);
        }

        return true;
    }

    /**
     * Sort order of the images follows the order of the references in the cell.
     *
     * @param  list<string>  $hashes
     */
    private function reorder(Product $product, array $hashes): void
    {
        $images = $product->images()->get(['id', 'source_hash', 'sort_order']);
        $byHash = [];

        foreach ($images as $image) {
            if ($image->source_hash !== null) {
                $byHash[$image->source_hash] = $image;
            }
        }

        $position = 0;

        foreach ($hashes as $hash) {
            $image = $byHash[$hash] ?? null;

            if ($image === null) {
                continue;
            }

            if ($image->sort_order !== $position) {
                ProductImage::query()->whereKey($image->id)->update(['sort_order' => $position]);
            }

            $position++;
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function category(array $task, MediaResolver $resolver, IssueBatch $issues): bool
    {
        $category = Category::query()->find((int) ($task['id'] ?? 0));
        $row = (int) ($task['row'] ?? 0);
        $ref = (string) (((array) ($task['refs'] ?? []))[0] ?? '');

        if ($category === null || $ref === '') {
            return true;
        }

        try {
            $path = $this->images->processCategoryImage($resolver->resolve($ref), $category);
            $category->update(['image_path' => $path]);
        } catch (ImageFetchException|ImageProcessingException $e) {
            $this->warn($issues, $row, 'import_process.warnings.category_photo', $ref, $e->getMessage(), CategorySheetImporter::columnPrefix());
        } catch (Throwable $e) {
            report($e);
            $this->warn($issues, $row, 'import_process.warnings.category_photo', $ref, __('catalog.errors.image_corrupt'), CategorySheetImporter::columnPrefix());
        }

        return true;
    }

    private function warn(IssueBatch $issues, int $row, string $key, string $ref, string $message, ?string $prefix = null): void
    {
        $column = __('import_process.photo_column');
        $issues->add($row, ImportIssueLevel::Warning, $prefix !== null ? $prefix.' / '.$column : $column, __($key, ['ref' => $ref, 'message' => $message]), $ref);
    }
}
