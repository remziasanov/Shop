<?php

declare(strict_types=1);

use App\Enums\SalesMode;

beforeEach(function () {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
});

it('shows a disabled «Нет в наличии» button and an inquiry for an out of stock product', function () {
    $product = sellable(['stock_qty' => 0, 'sales_mode' => SalesMode::Cart]);

    $this->get(route('product.show', $product->slug))
        ->assertOk()
        ->assertSee('data-action="unavailable"', false)
        ->assertSee('data-action="inquiry"', false)
        ->assertDontSee('data-action="cart"', false);
});

it('shows «Заказать (под заказ)» with backorder', function () {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 0, 'sales_mode' => SalesMode::Both]);

    $this->get(route('product.show', $product->slug))
        ->assertSee('Заказать (под заказ)')
        ->assertDontSee('data-action="reservation"', false);
});

it('shows normal buttons when in stock', function () {
    $product = sellable(['stock_qty' => 3, 'sales_mode' => SalesMode::Both]);

    $this->get(route('product.show', $product->slug))
        ->assertSee('data-action="cart"', false)
        ->assertSee('data-action="reservation"', false);
});
