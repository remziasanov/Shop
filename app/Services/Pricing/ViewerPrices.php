<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceSource;
use App\Models\CustomerGroup;
use App\Services\Catalog\CatalogVersion;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Display prices of the storefront for a customer group (GP-09, GP-10): wraps {@see PriceResolver} so that lists, the
 * product page and the variant picker share one rule and one batch load ({@see PriceResolver::preload()}).
 */
final class ViewerPrices
{
    public function __construct(private readonly PriceResolver $resolver) {}

    /**
     * Whether the group has any tiers or exact/discounted prices at all. A group without them pays the retail price
     * everywhere (GP-08), so lists skip the price queries for it. The flag comes with the viewer's group row (no query);
     * a group loaded otherwise is asked once per catalog version (every change of group prices moves it on, 26.3).
     */
    public function hasData(CustomerGroup $group): bool
    {
        if (! $group->exists) {
            return false;
        }

        // The viewer's group row carries the flag (CustomerGroup::scopeWithPriceData): no query at all.
        $flag = $group->hasPriceDataFlag();

        if ($flag !== null) {
            return $flag;
        }

        return Cache::remember('group-prices:'.$group->id.':'.CatalogVersion::current(), 600, fn (): bool => (bool) DB::scalar(
            'select (exists (select 1 from product_group_prices_final where customer_group_id = ?) or exists (select 1 from product_price_tiers where customer_group_id = ?))',
            [$group->id, $group->id],
        ));
    }

    /**
     * Load the data of all items of a page at once; null for a group without any price data (nothing to load).
     *
     * @param  iterable<Priceable>  $items
     */
    public function preload(iterable $items, CustomerGroup $group): ?PriceBook
    {
        return $this->hasData($group) ? $this->resolver->preload($items, $group) : null;
    }

    /**
     * The viewer's price of an item at quantity 1. Without a book a group with price data loads the item's own data; a
     * group without any pays the retail price (no queries).
     */
    public function for(Priceable $item, CustomerGroup $group, ?PriceBook $book = null): ViewerPrice
    {
        if ($book === null && ! $this->hasData($group)) {
            return new ViewerPrice($item->retailPriceKopecks(), PriceSource::Retail, $item->retailOldPriceKopecks(), null, []);
        }

        $resolved = $this->resolver->resolve($item, $group, 1, $book);
        $tiers = [];
        $book ??= $this->resolver->preload([$item], $group);

        foreach ($book->tiers($item) as $minQty => $price) {
            if ($minQty >= 2) {
                $tiers[] = ['min' => $minQty, 'price' => $price];
            }
        }

        return new ViewerPrice($resolved->unitPrice, $resolved->source, $resolved->oldPrice, $resolved->retailStrikePrice($group), $tiers);
    }

    /**
     * Lowest and highest price of the active variants of cards for the group, one query (VR-08, GP-06): a variant
     * without own group price counts with its retail price.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{min: int, max: int}>
     */
    public function variantRanges(array $productIds, CustomerGroup $group): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('product_variants as v')
            ->leftJoin('product_group_prices_final as pg', function ($join) use ($group): void {
                $join->on('pg.product_id', '=', 'v.product_id')
                    ->on('pg.variant_key', '=', 'v.id')
                    ->where('pg.customer_group_id', '=', (int) $group->id);
            })
            ->whereIn('v.product_id', $productIds)
            ->where('v.is_active', true)
            ->whereNull('v.deleted_at')
            ->groupBy('v.product_id')
            ->selectRaw('v.product_id, MIN(COALESCE(pg.final_price, v.final_price)) as lo, MAX(COALESCE(pg.final_price, v.final_price)) as hi')
            ->get();

        $ranges = [];

        foreach ($rows as $row) {
            $ranges[(int) $row->product_id] = ['min' => Money::fromRubles((string) $row->lo), 'max' => Money::fromRubles((string) $row->hi)];
        }

        return $ranges;
    }
}
