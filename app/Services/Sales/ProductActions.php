<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesMode;

/**
 * What a customer can do with a product right now (TZ 5.6): the effective mode adjusted by stock.
 *
 * UI contract:
 *  - the mode decides which buttons exist ({@see hasCartButton()}, {@see hasReservationButton()});
 *  - `canAddToCart` / `canReserve` tell whether those buttons are active;
 *  - `outOfStock`: the buttons of the mode are shown disabled as «Нет в наличии» and the inquiry button is offered;
 *  - `backorder`: the cart button reads «Заказать (под заказ)», the reservation button is not shown;
 *  - `maxQty`: limit for the cart (null = unlimited: stock not tracked or backorder allowed);
 *  - `maxReserveQty`: limit for a reservation (available stock; null = stock not tracked).
 */
final readonly class ProductActions
{
    public function __construct(
        public SalesMode $mode,
        public bool $canAddToCart,
        public bool $canReserve,
        public bool $canInquire,
        public bool $backorder,
        public bool $outOfStock,
        public ?int $maxQty,
        public bool $showPrice,
        public ?int $maxReserveQty = null,
    ) {}

    public function hasCartButton(): bool
    {
        return $this->mode === SalesMode::Cart || $this->mode === SalesMode::Both;
    }

    public function hasReservationButton(): bool
    {
        return $this->mode === SalesMode::Reservation || $this->mode === SalesMode::Both;
    }
}
