<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The checkout switch «Оформить заказ» / «Зарезервировать» (MR-04): the part of the checkout a cart row goes to.
 */
enum CheckoutChoice: string
{
    case Order = 'order';
    case Reservation = 'reservation';
}
