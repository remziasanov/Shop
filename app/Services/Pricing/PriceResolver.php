<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceSource;
use App\Models\CustomerGroup;
use App\Settings\PricingSettings;

/**
 * The single place that decides the unit price of an item for a customer group and a quantity (GP-03, TZ 21.2, D-080).
 * The first matching rule wins:
 *
 *  1. the tier of the group with the largest `min_qty` <= quantity (`min_qty` >= 2);
 *  2. the exact price of the group (`min_qty` = 1);
 *  3. the denormalized group price (rules bound to the group and the group discount, GP-06);
 *  4. the retail `final_price`.
 *
 * Lists load the data of all their items at once with {@see preload()} and pass the {@see PriceBook} to every
 * {@see resolve()} call; without a book the item's data is loaded by two queries (fine for a single item, not for a loop).
 */
class PriceResolver
{
    /**
     * Load tiers and group prices of the items for the group (two queries per 500 products).
     *
     * @param  iterable<Priceable>  $items
     */
    public function preload(iterable $items, CustomerGroup $group): PriceBook
    {
        return PriceBook::load($items, $group->id);
    }

    public function resolve(Priceable $item, CustomerGroup $group, int $qty, ?PriceBook $book = null): ResolvedPrice
    {
        if ($book === null || ! $book->covers($item, $group->id)) {
            $book = $this->preload([$item], $group);
        }

        $qty = max(1, $qty);
        $retail = $item->retailPriceKopecks();
        $tiers = $book->tiers($item);

        $matched = null;
        $next = null;

        foreach ($tiers as $minQty => $price) {
            if ($minQty < 2) {
                continue;
            }

            if ($minQty <= $qty) {
                $matched = $minQty;
            } elseif ($next === null) {
                $next = new NextTier($minQty, $price);
            }
        }

        if ($matched !== null) {
            return new ResolvedPrice($tiers[$matched], PriceSource::Tier, $matched, null, $retail, $next);
        }

        if (isset($tiers[1])) {
            return new ResolvedPrice($tiers[1], PriceSource::GroupPrice, null, null, $retail, $next);
        }

        $row = $book->groupRow($item);

        if ($row !== null) {
            return new ResolvedPrice($row['price'], PriceSource::GroupDiscount, null, $row['old'], $retail, $next);
        }

        return new ResolvedPrice($retail, PriceSource::Retail, null, $item->retailOldPriceKopecks(), $retail, $next);
    }

    /**
     * The hint of the cart (GP-11): shown when the next tier is cheaper and the missing quantity is at most
     * `tier_hint_percent` % of the current quantity or at most `tier_hint_qty` pieces (GP-19 settings).
     */
    public function tierHint(ResolvedPrice $price, int $qty): ?TierHint
    {
        $next = $price->nextTier;
        $settings = app(PricingSettings::class);

        if ($next === null || ! $settings->tier_hint_enabled || $next->price >= $price->unitPrice) {
            return null;
        }

        $qty = max(1, $qty);
        $missing = $next->minQty - $qty;

        if ($missing <= 0) {
            return null;
        }

        $withinPercent = $missing * 100 <= $qty * $settings->tier_hint_percent;

        if (! $withinPercent && $missing > $settings->tier_hint_qty) {
            return null;
        }

        return new TierHint($missing, $next->minQty, $next->price);
    }
}
