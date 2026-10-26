<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\CartChangedException;
use App\Services\Sales\DeliveryOption;

/**
 * Cart page data (SF-42, SF-43, SF-45). Money is in kopecks.
 *
 * `itemsTotal` counts only purchasable lines at current prices. `deliveryOptions` maps the key of each active
 * delivery method to its price for this total. `minOrderMissing` is how much is lacking to the minimum
 * (0 = reached); `canCheckout` is false for an empty cart or an unreached minimum. Lines with an issue
 * are resolved by checkout itself ({@see CartChangedException}).
 */
final readonly class CartSummary
{
    /**
     * @param  list<CartLine>  $lines
     * @param  list<array{method: DeliveryOption, price: int, free: bool}>  $deliveryOptions
     */
    public function __construct(
        public array $lines,
        public int $itemsTotal,
        public array $deliveryOptions,
        public int $minOrderAmount,
        public int $minOrderMissing,
        public bool $hasChanges,
        public bool $canCheckout,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
