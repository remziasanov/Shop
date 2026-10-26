<?php

declare(strict_types=1);

use App\Services\Sales\CheckoutMethods;

it('builds stable slug keys and skips inactive methods', function () {
    sales(['delivery_methods' => [
        ['name' => 'Самовывоз', 'description' => null, 'price' => 0, 'free_from' => null, 'requires_address' => false, 'is_active' => true],
        ['name' => 'Курьер', 'description' => 'Быстро', 'price' => 30000, 'free_from' => 500000, 'requires_address' => true, 'is_active' => false],
        ['name' => 'Курьер', 'description' => null, 'price' => 40000, 'free_from' => null, 'requires_address' => true, 'is_active' => true],
    ]]);

    $methods = app(CheckoutMethods::class);
    $keys = array_map(fn ($m) => $m->key, $methods->deliveries());

    // The inactive duplicate still occupies "kurer", so the active one keeps "kurer-2" whatever its neighbours do.
    expect($keys)->toBe(['samovyvoz', 'kurer-2'])
        ->and($methods->delivery('kurer-2')->price)->toBe(40000)
        ->and($methods->delivery('kurer'))->toBeNull();
});

it('computes free delivery from the threshold', function () {
    $courier = app(CheckoutMethods::class)->delivery('kurerskaia-dostavka');

    expect($courier->priceFor(499999))->toBe(30000)->and($courier->priceFor(500000))->toBe(0);
});

it('lists active payment methods', function () {
    $payments = app(CheckoutMethods::class)->payments();

    expect(array_map(fn ($p) => $p->key, $payments))->toBe(['nalicnymi-pri-polucenii', 'kartoi-pri-polucenii'])
        ->and(app(CheckoutMethods::class)->payment('nope'))->toBeNull();
});
