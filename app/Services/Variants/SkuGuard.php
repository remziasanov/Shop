<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Article uniqueness across products and variants (VR-04, D-087): `products.sku` and `product_variants.sku` have their own
 * unique indexes, this service checks the other table. Call it inside the transaction that writes the SKU. Soft-deleted
 * rows keep their SKU (the unique indexes count them too). A rare race between two parallel writes of one SKU into the two
 * tables is found by {@see collisions()} (`shop:doctor`).
 */
final class SkuGuard
{
    public const MAX_LENGTH = 100;

    /**
     * Whether another product or variant already uses the SKU.
     *
     * @param  Product|ProductVariant|null  $ignore  the record being saved, so it does not collide with itself
     */
    public function isTaken(string $sku, Product|ProductVariant|null $ignore = null): bool
    {
        $products = DB::table('products')->where('sku', $sku);
        $variants = DB::table('product_variants')->where('sku', $sku);

        if ($ignore instanceof Product && $ignore->exists) {
            $products->where('id', '!=', $ignore->id);
        }

        if ($ignore instanceof ProductVariant && $ignore->exists) {
            $variants->where('id', '!=', $ignore->id);
        }

        return $products->exists() || $variants->exists();
    }

    /**
     * Which of the SKUs are taken (one query per table).
     *
     * @param  list<string>  $skus
     * @return list<string>
     */
    public function takenAmong(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $taken = [];

        foreach (['products', 'product_variants'] as $table) {
            foreach (DB::table($table)->whereIn('sku', $skus)->pluck('sku') as $sku) {
                $taken[(string) $sku] = (string) $sku;
            }
        }

        return array_values($taken);
    }

    /**
     * @param  Product|ProductVariant|null  $ignore  the record being saved
     *
     * @throws ValidationException on an empty, too long or taken SKU (error key `sku`)
     */
    public function assertAvailable(string $sku, Product|ProductVariant|null $ignore = null): void
    {
        if ($sku === '') {
            return;
        }

        if (mb_strlen($sku) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['sku' => __('variants.errors.sku_too_long', ['max' => self::MAX_LENGTH])]);
        }

        if ($this->isTaken($sku, $ignore)) {
            throw ValidationException::withMessages(['sku' => __('variants.errors.sku_taken', ['sku' => $sku])]);
        }
    }

    /**
     * First free SKU: "{base}", then "{base}-2", "{base}-3", … ($suffix replaces the numbering prefix, e.g. "-copy":
     * "{base}-copy", "{base}-copy-2", …).
     */
    public function firstFree(string $base, string $suffix = ''): string
    {
        for ($n = 1; ; $n++) {
            $tail = $suffix.($n === 1 ? '' : '-'.$n);
            $candidate = Str::limit($base, self::MAX_LENGTH - strlen($tail), '').$tail;

            if (! $this->isTaken($candidate)) {
                return $candidate;
            }
        }
    }

    /**
     * SKUs used by both a product and a variant (race leftovers, `shop:doctor`).
     *
     * @return list<string>
     */
    public function collisions(): array
    {
        return DB::table('products')
            ->join('product_variants', 'product_variants.sku', '=', 'products.sku')
            ->orderBy('products.sku')
            ->pluck('products.sku')
            ->map(fn (mixed $sku): string => (string) $sku)
            ->all();
    }
}
