<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceSource;

/**
 * The price of one item as the storefront viewer's group sees it at quantity 1 (GP-09, GP-10), with the quantity tiers of
 * that group only (GP-21.7: prices of other groups never reach the page). Money is integer kopecks.
 *
 * `oldPrice` is the struck-through price of the shop's own discounts for the viewer; `retailStrike` is the retail price
 * to strike through when the group has `show_retail_price` and pays less than retail; `tiers` are the tiers with
 * `min_qty` >= 2 in ascending order (`min` => quantity, `price` => unit price).
 */
final readonly class ViewerPrice
{
    /**
     * @param  list<array{min: int, price: int}>  $tiers
     */
    public function __construct(
        public int $unit,
        public PriceSource $source,
        public ?int $oldPrice,
        public ?int $retailStrike,
        public array $tiers,
    ) {}

    /**
     * Unit price for a quantity: the last tier reached, otherwise the price at quantity 1.
     */
    public function unitFor(int $qty): int
    {
        $price = $this->unit;

        foreach ($this->tiers as $tier) {
            if ($tier['min'] <= $qty) {
                $price = $tier['price'];
            }
        }

        return $price;
    }

    /**
     * Saving in whole percent of a tier price against the price at quantity 1.
     */
    public function saving(int $tierPrice): int
    {
        return $this->unit <= 0 || $tierPrice >= $this->unit ? 0 : (int) round(($this->unit - $tierPrice) * 100 / $this->unit);
    }
}
