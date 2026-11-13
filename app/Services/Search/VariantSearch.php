<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search by the article or barcode of a variant (VR-21): `products.search_text` is not extended, an exact match on the
 * active variants (unique `sku` index, `barcode` index; no LIKE) is OR-ed to the word search of the card instead. A card
 * found that way comes first and opens on the matching variant (`?variant={id}`).
 */
final class VariantSearch
{
    /**
     * The phrase as typed (trimmed): articles are matched exactly, the database collation decides about the case.
     */
    public function exactTerm(string $term): string
    {
        return trim($term);
    }

    /**
     * `EXISTS (…)` of active variants of `products` whose article or barcode equals the phrase.
     */
    public function constrain(QueryBuilder $exists, string $productIdColumn, string $term): void
    {
        $exists->selectRaw('1')
            ->from('product_variants as pv')
            ->whereColumn('pv.product_id', $productIdColumn)
            ->where('pv.is_active', true)
            ->whereNull('pv.deleted_at')
            ->where(fn (QueryBuilder $match) => $match->where('pv.sku', $term)->orWhere('pv.barcode', $term));
    }

    /**
     * Product id => variant id for the cards of a result page that were found by the article or barcode of a variant.
     * One query, and none when no card of the page has variants.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, int>
     */
    public function linksFor(string $term, Collection $products): array
    {
        $term = $this->exactTerm($term);
        $ids = $products->filter(fn (Product $product): bool => $product->has_variants)->map(fn (Product $product): int => $product->id)->values()->all();

        if ($term === '' || $ids === []) {
            return [];
        }

        $links = [];

        $rows = DB::table('product_variants as pv')
            ->whereIn('pv.product_id', $ids)
            ->where('pv.is_active', true)
            ->whereNull('pv.deleted_at')
            ->where(fn (QueryBuilder $match) => $match->where('pv.sku', $term)->orWhere('pv.barcode', $term))
            ->orderBy('pv.id')
            ->get(['pv.id', 'pv.product_id']);

        foreach ($rows as $row) {
            $links[(int) $row->product_id] ??= (int) $row->id;
        }

        return $links;
    }
}
