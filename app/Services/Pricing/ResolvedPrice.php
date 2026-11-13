<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceSource;
use App\Models\CustomerGroup;

/**
 * Outcome of {@see PriceResolver::resolve()} (GP-03). Money is integer kopecks, per unit.
 *
 * `oldPrice` is the struck-through price of the shop's own discounts (rules, compare-at price) and only exists for the
 * `group_discount` and `retail` sources; `retailPrice` is the retail price of the item (what the order line stores as
 * `retail_price`, and what a group with `show_retail_price` shows struck through).
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public int $unitPrice,
        public PriceSource $source,
        public ?int $tierMinQty,
        public ?int $oldPrice,
        public int $retailPrice,
        public ?NextTier $nextTier,
    ) {}

    /**
     * The retail price to show struck through next to the group price (GP-09), or null when the group does not show it
     * or its price is not below the retail one.
     */
    public function retailStrikePrice(CustomerGroup $group): ?int
    {
        return $group->show_retail_price && $this->unitPrice < $this->retailPrice ? $this->retailPrice : null;
    }

    public function lineTotal(int $qty): int
    {
        return $this->unitPrice * $qty;
    }
}
