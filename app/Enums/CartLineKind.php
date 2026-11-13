<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a cart row can be checked out (MR-02): `order` (mode «Корзина»), `reservation` (mode «Резерв») or `flexible`
 * (mode «Корзина и резерв»: the customer chooses at checkout). Resolved on every cart load and at checkout, never stored.
 */
enum CartLineKind: string
{
    case Order = 'order';
    case Reservation = 'reservation';
    case Flexible = 'flexible';

    /**
     * Where the row goes for the customer's choice for flexible rows.
     */
    public function target(CheckoutChoice $choice): CheckoutChoice
    {
        return match ($this) {
            self::Order => CheckoutChoice::Order,
            self::Reservation => CheckoutChoice::Reservation,
            self::Flexible => $choice,
        };
    }
}
