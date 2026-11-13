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
 * (0 = reached); `canCheckout` is false for an empty cart or an unreached minimum. MR-08: `itemsTotal`, the minimum
 * and the delivery options belong to the order part only; `reservationTotal` is the sum of the rows that go to the
 * reservation (for the choice the summary was built with); `hasFlexible` tells that the customer has a choice, and
 * the minimum does not block a cart with such rows (the order part may be empty after the choice). Lines with an issue
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
        public int $reservationTotal = 0,
        public bool $hasOrder = false,
        public bool $hasReservation = false,
        public bool $hasFlexible = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Whether the customer may go on to the checkout page: the minimum does not stop a cart with a choice there.
     */
    public function canProceed(): bool
    {
        return $this->lines !== [] && ($this->minOrderMissing === 0 || $this->hasFlexible || ! $this->hasOrder);
    }
}
