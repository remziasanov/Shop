<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Duplicate" action (AD-43): an inactive copy with the SKU "{sku}-copy" (or "-copy-2", …), the same
 * name and characteristics, an automatic slug and its own copies of the photo files.
 */
final class ProductDuplicator
{
    private const SKU_MAX_LENGTH = 100;

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    public function duplicate(Product $source): Product
    {
        $disk = $this->filesystems->disk(ImageProcessor::DISK);
        /** @var list<string> $copiedFiles */
        $copiedFiles = [];

        try {
            return DB::transaction(function () use ($source, $disk, &$copiedFiles): Product {
                $copy = new Product($this->copyAttributes($source));
                $copy->save();

                foreach ($source->images()->get() as $image) {
                    $this->copyImage($image, $copy, $disk, $copiedFiles);
                }

                $copy->unsetRelation('images');

                return $copy;
            });
        } catch (Throwable $e) {
            // The transaction rolled the rows back; do not leave orphan files behind.
            $disk->delete($copiedFiles);

            throw $e;
        }
    }

    /**
     * First free SKU: "{sku}-copy", "{sku}-copy-2", … (trashed products keep their SKU too).
     */
    public function copySku(string $sku): string
    {
        for ($n = 1; ; $n++) {
            $suffix = $n === 1 ? '-copy' : '-copy-'.$n;
            $candidate = Str::limit($sku, self::SKU_MAX_LENGTH - strlen($suffix), '').$suffix;

            if (! Product::withTrashed()->where('sku', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function copyAttributes(Product $source): array
    {
        $data = [];

        foreach ($source->getFillable() as $field) {
            $data[$field] = $source->getAttribute($field);
        }

        return array_merge($data, [
            'sku' => $this->copySku($source->sku),
            // Empty slug => generated from the name by the observer.
            'slug' => '',
            'is_active' => false,
            'attributes' => $source->getAttribute('attributes'),
        ]);
    }

    /**
     * @param  list<string>  $copiedFiles  collects the written paths for cleanup on failure
     */
    private function copyImage(ProductImage $image, Product $copy, Filesystem $disk, array &$copiedFiles): void
    {
        $paths = [];

        foreach (['path_large', 'path_medium', 'path_small'] as $column) {
            $from = $image->{$column};

            if (! $disk->exists($from)) {
                // A photo whose files are gone cannot be copied; skip it instead of failing the whole copy.
                return;
            }

            $paths[$column] = $from;
        }

        $uuid = Str::uuid()->toString();
        $row = [];

        foreach ($paths as $column => $from) {
            $extension = pathinfo($from, PATHINFO_EXTENSION);
            $size = match ($column) {
                'path_large' => ProductImage::SIZE_LARGE,
                'path_medium' => ProductImage::SIZE_MEDIUM,
                default => ProductImage::SIZE_SMALL,
            };
            $to = "products/{$copy->id}/{$uuid}-{$size}.{$extension}";

            if (! $disk->copy($from, $to)) {
                throw new \RuntimeException("Could not copy {$from} to {$to}.");
            }

            $copiedFiles[] = $to;
            $row[$column] = $to;
        }

        $copy->images()->create($row + [
            'alt' => $image->alt,
            'sort_order' => $image->sort_order,
            'width' => $image->width,
            'height' => $image->height,
            'source_hash' => $image->source_hash,
        ]);
    }
}
