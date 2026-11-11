<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Settings\CatalogSettings;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the display cache `products.attributes` (JSON `[{name, value}]`, FL-02/20.2) from the normalized tables.
 * The only writer of that column from version 2.0 on (the v1 importer still writes it until it moves to
 * {@see AttributeService}); {@see Product::specs()} keeps reading it.
 *
 * Format: characteristics in dictionary order (`attributes.sort_order`, then id), inactive ones left out; list
 * values joined with ", " in dictionary order; numbers without trailing zeros and with the unit ("50 см");
 * booleans as «да» / «нет».
 *
 * Until the migration from JSON is finished (`CatalogSettings::attributes_migrated_at` is null), a product that has
 * no rows in the tables but still has JSON is left alone, unless the caller forces the rebuild: the JSON is its only
 * copy of the characteristics.
 */
final class AttributeSyncService
{
    public const CHUNK = 500;

    /**
     * Rebuild the JSON of one product (also refreshes the given model in memory).
     */
    public function syncProduct(Product|int $product, bool $force = false): void
    {
        $id = $product instanceof Product ? $product->id : $product;
        $this->syncProducts([$id], $force);

        if ($product instanceof Product) {
            $json = DB::table('products')->where('id', $id)->value('attributes');
            $product->forceFill(['attributes' => is_string($json) ? json_decode($json, true) : null]);
            $product->syncOriginalAttribute('attributes');
        }
    }

    /**
     * Rebuild the JSON of the products (chunked by {@see CHUNK}); returns how many rows were changed.
     *
     * @param  array<int>  $productIds
     * @param  bool  $force  also clear the JSON of products without rows before the migration is finished
     */
    public function syncProducts(array $productIds, bool $force = false): int
    {
        $changed = 0;
        $migrated = $force || app(CatalogSettings::class)->attributes_migrated_at !== null;

        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::CHUNK) as $chunk) {
            $changed += $this->syncChunk($chunk, $migrated);
        }

        return $changed;
    }

    /**
     * Rebuild every product (`shop:rebuild-attribute-cache`), chunked by id; returns how many rows were changed.
     *
     * @param  Closure(int $processed): void|null  $progress  called after each chunk with the number of products seen
     */
    public function rebuildAll(?Closure $progress = null): int
    {
        $changed = 0;
        $processed = 0;
        $migrated = app(CatalogSettings::class)->attributes_migrated_at !== null;

        DB::table('products')->select('id')->orderBy('id')->chunkById(self::CHUNK, function ($rows) use (&$changed, &$processed, $migrated, $progress): void {
            $changed += $this->syncChunk($rows->pluck('id')->map(fn ($id): int => (int) $id)->all(), $migrated);
            $processed += $rows->count();

            if ($progress !== null) {
                $progress($processed);
            }
        });

        return $changed;
    }

    /**
     * The JSON content for products, from the tables (nothing is written). Products without values are absent.
     *
     * @param  array<int>  $productIds
     * @return array<int, list<array{name: string, value: string}>>
     */
    public function specsFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'a.id', '=', 'pav.attribute_id')
            ->leftJoin('attribute_values as v', 'v.id', '=', 'pav.attribute_value_id')
            ->whereIn('pav.product_id', $productIds)
            ->where('a.is_active', true)
            ->orderBy('pav.product_id')->orderBy('a.sort_order')->orderBy('a.id')
            ->orderBy('v.sort_order')->orderBy('v.id')
            ->get(['pav.product_id', 'a.id as attribute_id', 'a.name', 'a.unit', 'a.type', 'v.value', 'pav.value_number', 'pav.value_bool']);

        /** @var array<int, array<int, array{name: string, unit: ?string, type: string, parts: list<string>}>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $productId = (int) $row->product_id;
            $attributeId = (int) $row->attribute_id;
            $grouped[$productId][$attributeId] ??= ['name' => (string) $row->name, 'unit' => $row->unit !== null ? (string) $row->unit : null, 'type' => (string) $row->type, 'parts' => []];

            $part = match ($row->type) {
                'number' => $this->numberText($row->value_number, $row->unit),
                'boolean' => $row->value_bool === null ? null : ((bool) $row->value_bool ? __('attributes.boolean_yes') : __('attributes.boolean_no')),
                default => $row->value !== null ? (string) $row->value : null,
            };

            if ($part !== null && $part !== '') {
                $grouped[$productId][$attributeId]['parts'][] = $part;
            }
        }

        $specs = [];

        foreach ($grouped as $productId => $attributes) {
            foreach ($attributes as $attribute) {
                if ($attribute['parts'] !== []) {
                    $specs[$productId][] = ['name' => $attribute['name'], 'value' => implode(', ', $attribute['parts'])];
                }
            }
        }

        return $specs;
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncChunk(array $ids, bool $migrated): int
    {
        $specs = $this->specsFor($ids);
        $current = DB::table('products')->whereIn('id', $ids)->pluck('attributes', 'id');
        $changed = 0;

        foreach ($ids as $id) {
            if (! $current->has($id)) {
                continue;
            }

            $new = $specs[$id] ?? null;
            $old = $current[$id] !== null ? json_decode((string) $current[$id], true) : null;
            $old = $old === [] ? null : $old;

            if (! $migrated && $new === null && $old !== null) {
                continue;
            }

            if ($new === $old) {
                continue;
            }

            DB::table('products')->where('id', $id)->update([
                'attributes' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $changed++;
        }

        return $changed;
    }

    private function numberText(mixed $number, mixed $unit): ?string
    {
        if ($number === null) {
            return null;
        }

        $text = AttributeValueParser::formatNumber(is_string($number) ? $number : (float) $number);

        return $unit !== null && $unit !== '' ? $text.' '.$unit : $text;
    }
}
