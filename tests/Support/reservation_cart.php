<?php

declare(strict_types=1);

use App\Enums\CheckoutChoice;
use App\Enums\SalesMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Orders\CheckoutData;
use Illuminate\Support\Carbon;

/**
 * Helpers of the reservation-via-cart tests (MR-01…MR-13).
 */

/**
 * @param  list<array{0: Product, 1?: int}>  $rows
 */
function mrCart(array $rows): Cart
{
    $cart = Cart::factory()->create();

    foreach ($rows as $row) {
        CartItem::factory()->forProduct($row[0], $row[1] ?? 1)->create(['cart_id' => $cart->id]);
    }

    return $cart;
}

function mrReservable(array $attributes = []): Product
{
    return sellable(array_merge(['sales_mode' => SalesMode::Reservation], $attributes));
}

/** Checkout data of a pure reservation: no delivery and payment (MR-06). */
function mrReserveData(?DateTimeInterface $desired = null, CheckoutChoice $choice = CheckoutChoice::Reservation): CheckoutData
{
    return new CheckoutData(
        name: 'Иван Тестов',
        phone: '8 (900) 123-45-67',
        email: 'ivan@example.com',
        deliveryKey: '',
        address: null,
        paymentKey: '',
        comment: null,
        consent: true,
        ip: '127.0.0.1',
        userAgent: 'Pest',
        choice: $choice,
        desiredDate: $desired === null ? null : Carbon::instance($desired),
    );
}

function mrMixedData(CheckoutChoice $choice = CheckoutChoice::Reservation): CheckoutData
{
    return new CheckoutData(
        name: 'Иван Тестов',
        phone: '8 (900) 123-45-67',
        email: 'ivan@example.com',
        deliveryKey: 'samovyvoz',
        address: null,
        paymentKey: 'nalicnymi-pri-polucenii',
        comment: null,
        consent: true,
        ip: '127.0.0.1',
        userAgent: 'Pest',
        choice: $choice,
    );
}
