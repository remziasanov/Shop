<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\CartLineKind;
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

    /**
     * How the product can be checked out from the cart (MR-01, MR-02), null when it cannot be in the cart now:
     * a «Корзина» product is an `order` row; a «Резерв» product a `reservation` row (only while it can be reserved, so
     * a back-ordered product without stock never is); a «Корзина и резерв» product is `flexible` when both work and
     * falls back to `order` (reservation switched off, no stock to hold). Inquiry and showcase products never.
     */
    public function cartKind(bool $reservationViaCart): ?CartLineKind
    {
        $reservable = $reservationViaCart && $this->canReserve;

        return match ($this->mode) {
            SalesMode::Cart => $this->canAddToCart ? CartLineKind::Order : null,
            SalesMode::Reservation => $reservable ? CartLineKind::Reservation : null,
            SalesMode::Both => match (true) {
                $reservable && $this->canAddToCart => CartLineKind::Flexible,
                $reservable => CartLineKind::Reservation,
                $this->canAddToCart => CartLineKind::Order,
                default => null,
            },
            default => null,
        };
    }

    /**
     * Quantity limit of a cart row of this kind (null = unlimited): the cart limit for an order and a flexible row, the
     * available stock for a reservation row. A flexible row may exceed the stock when «под заказ» is on; choosing the
     * reservation for it is then refused at checkout ({@see maxReserveQty}).
     */
    public function cartMaxQty(?CartLineKind $kind): ?int
    {
        return $kind === CartLineKind::Reservation ? $this->maxReserveQty : $this->maxQty;
    }
}
