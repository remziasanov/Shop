<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Livewire\Storefront\CartPage;
use App\Models\CartItem;

beforeEach(function () {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

it('shows the empty state without a cart', function () {
    $this->get(route('cart'))->assertOk()->assertSee('В корзине пока ничего нет.')->assertSee('noindex', false);
});

it('lists items with name link, price, quantity and totals (SF-42)', function () {
    $product = sellable(['name' => 'Лампа Сфера', 'base_price' => '700.00']);
    $cart = cartWith($product);
    $cart->items()->update(['qty' => 3]);

    $this->withCookie('cart', $cart->token)->get(route('cart'))->assertOk()
        ->assertSee('Лампа Сфера')
        ->assertSee(route('product.show', $product->slug), false)
        ->assertSeeText("2\u{202F}100\u{00A0}₽")
        ->assertSee('data-checkout', false);
});

it('shows a prominent notice after a price change and clears it with «Понятно» (SF-42, PR-07)', function () {
    $product = sellable(['name' => 'Лампа Сфера', 'base_price' => '700.00']);
    $cart = cartWith($product);
    $product->update(['base_price' => '900.00']);

    livewireWithCart(CartPage::class, $cart)
        ->assertSee('Цены изменились')
        ->assertSee('Лампа Сфера')
        ->assertSeeHtml('data-price-changed')
        ->call('acknowledge')
        ->assertDontSeeHtml('data-price-changed');

    expect($cart->items()->first()->price_at_add)->toBe('900.00');
});

it('shows no notice when prices are unchanged', function () {
    livewireWithCart(CartPage::class, cartWith(sellable()))->assertDontSeeHtml('data-price-changed');
});

it('disables checkout and explains the minimum order amount (SF-43)', function () {
    sales(['min_order_amount' => 300000]);
    $cart = cartWith(sellable(['base_price' => '1000.00']));

    livewireWithCart(CartPage::class, $cart)
        ->assertSeeHtml('data-checkout-disabled')
        ->assertDontSeeHtml('data-checkout>')
        ->assertSee('Минимальная сумма заказа')
        ->assertSeeText("2\u{202F}000\u{00A0}₽");

    $this->withCookie('cart', $cart->token)->get(route('checkout'))->assertRedirect(route('cart'));
});

it('changes the quantity within the stock and removes rows', function () {
    $product = sellable(['stock_qty' => 4, 'sales_mode' => SalesMode::Cart]);
    $cart = cartWith($product);

    livewireWithCart(CartPage::class, $cart)
        ->set('qtys.'.$product->id, 3)
        ->assertHasNoErrors();
    expect($cart->items()->first()->qty)->toBe(3);

    livewireWithCart(CartPage::class, $cart)->set('qtys.'.$product->id, 9);
    expect($cart->items()->first()->qty)->toBe(4);

    livewireWithCart(CartPage::class, $cart)
        ->call('remove', $product->id)
        ->assertSee('В корзине пока ничего нет.')
        ->assertDispatched('cart-updated');
    expect(CartItem::query()->count())->toBe(0);
});

it('explains why an item became unavailable', function () {
    $product = sellable(['stock_qty' => 5]);
    $cart = cartWith($product);
    $product->update(['stock_qty' => 0]);

    livewireWithCart(CartPage::class, $cart)->assertSee('нет в наличии');
});

it('shows the reasons flashed by a failed checkout (SF-46)', function () {
    session()->flash('cart_changed', ['Цена товара «Лампа» изменилась: было 1 ₽, стало 2 ₽.']);

    livewireWithCart(CartPage::class)->assertSeeHtml('data-cart-changed')->assertSee('Цена товара «Лампа» изменилась');
});
