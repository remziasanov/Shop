<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Media\ImageProcessor;
use App\Services\Stock\StockService;
use App\Services\Variants\SkuGuard;
use App\Services\Variants\VariantAggregator;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Duplicate" action (AD-43): an inactive copy with the SKU "{sku}-copy" (or "-copy-2", …), the same
 * name and characteristics, an automatic slug and its own copies of the photo files. A card with variants gets copies of
 * its axes and variants with the SKUs "{sku}-copy" (unique among products and variants, SkuGuard) and zeroed stock (VR-27).
 */
final class ProductDuplicator
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly AttributeService $attributes,
        private readonly SkuGuard $skus,
        private readonly VariantAggregator $aggregator,
        private readonly StockService $stock,
    ) {}

    public function duplicate(Product $source): Product
    {
        $disk = $this->filesystems->disk(ImageProcessor::DISK);
        /** @var list<string> $copiedFiles */
        $copiedFiles = [];

        try {
            return DB::transaction(function () use ($source, $disk, &$copiedFiles): Product {
                $copy = new Product($this->copyAttributes($source));
                $copy->has_variants = (bool) $source->has_variants;
                $copy->save();

                if ($source->has_variants) {
                    $this->copyVariants($source, $copy);
                }

                // 20.6: the normalized characteristics follow the copy (and refresh its JSON cache).
                $this->attributes->copyProductValues($source, $copy);

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
     * First free SKU: "{sku}-copy", "{sku}-copy-2", … (trashed products and variants keep their SKU too; the SKU
     * is unique among products and variants, VR-04).
     */
    public function copySku(string $sku): string
    {
        return $this->skus->firstFree($sku, '-copy');
    }

    /**
     * Axes and variants of the source for the copy: same option values and prices, barcode and stock reset (stock
     * 0 for a tracked variant, VR-27), SKU "{sku}-copy".
     */
    private function copyVariants(Product $source, Product $copy): void
    {
        $this->aggregator->batch(function () use ($source, $copy): void {
            foreach (DB::table('product_options')->where('product_id', $source->id)->orderBy('sort_order')->get() as $axis) {
                DB::table('product_options')->insert(['product_id' => $copy->id, 'option_type_id' => $axis->option_type_id, 'sort_order' => $axis->sort_order]);
            }

            foreach ($source->variants()->get() as $variant) {
                $new = new ProductVariant([
                    'base_price' => $variant->base_price,
                    'cost_price' => $variant->cost_price,
                    'compare_at_price' => $variant->compare_at_price,
                    'fixed_price' => $variant->fixed_price,
                    'is_active' => $variant->is_active,
                    'sort_order' => $variant->sort_order,
                ]);
                $new->product_id = $copy->id;
                $new->sku = $this->skus->firstFree($variant->sku, '-copy');
                $new->combo_hash = $variant->combo_hash;
                $new->setRelation('product', $copy);
                $new->save();

                foreach (DB::table('product_variant_values')->where('variant_id', $variant->id)->get() as $value) {
                    DB::table('product_variant_values')->insert([
                        'variant_id' => $new->id,
                        'option_type_id' => $value->option_type_id,
                        'option_value_id' => $value->option_value_id,
                    ]);
                }

                if ($variant->stock_qty !== null) {
                    // Zeroed through StockService; the card aggregate is rolled up there and when the batch ends.
                    $this->stock->setStock($new, 0);
                }
            }
        });
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
