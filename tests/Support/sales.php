<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\InquiryData;
use App\Services\Orders\ReservationData;
use App\Settings\SalesSettings;
use Illuminate\Support\Carbon;

/**
 * Sets sales settings for a test (defaults come from the settings migration: cart mode, stock tracking on,
 * no backorder, pickup + courier delivery).
 *
 * @param  array<string, mixed>  $values
 */
function sales(array $values = []): SalesSettings
{
    $settings = app(SalesSettings::class);

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

/**
 * A product visible on the storefront.
 *
 * @param  array<string, mixed>  $attributes
 */
function sellable(array $attributes = []): Product
{
    return Product::factory()->inCategory(Category::factory()->create())->create(array_merge([
        'base_price' => '1000.00',
        'stock_qty' => 10,
        'reserved_qty' => 0,
        'sales_mode' => SalesMode::Both,
    ], $attributes));
}

function cartWith(Product ...$products): Cart
{
    $cart = Cart::factory()->create();

    foreach ($products as $product) {
        CartItem::factory()->forProduct($product)->create(['cart_id' => $cart->id]);
    }

    return $cart;
}

function checkoutData(string $delivery = 'samovyvoz', bool $consent = true): CheckoutData
{
    return new CheckoutData(
        name: 'Иван Тестов',
        phone: '8 (900) 123-45-67',
        email: 'ivan@example.com',
        deliveryKey: $delivery,
        address: $delivery === 'samovyvoz' ? null : 'г. Тестоград, ул. Примерная, 1',
        paymentKey: 'nalicnymi-pri-polucenii',
        comment: null,
        consent: $consent,
        ip: '127.0.0.1',
        userAgent: 'Pest',
    );
}

function reservationData(int $qty = 1, ?DateTimeInterface $desired = null, bool $consent = true): ReservationData
{
    return new ReservationData($qty, 'Иван Тестов', '+7 900 123-45-67', null, $desired === null ? null : Carbon::instance($desired), null, $consent, '127.0.0.1', 'Pest');
}

function inquiryData(int $qty = 1): InquiryData
{
    return new InquiryData($qty, 'Иван Тестов', '+7 900 123-45-67', null, 'Нужна цена', true, '127.0.0.1', 'Pest');
}
