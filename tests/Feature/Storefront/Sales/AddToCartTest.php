<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Livewire\Storefront\AddToCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cookie;

beforeEach(function () {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
});

it('adds a cart-mode product from the product page, creates the cart lazily and announces it (SF-41)', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 5]);

    expect(Cart::query()->count())->toBe(0);

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id, 'showQty' => true])
        ->set('qty', 3)
        ->call('add')
        ->assertHasNoErrors()
        ->assertDispatched('cart-updated')
        ->assertDispatched('cart-toast', message: 'Товар добавлен в корзину');

    expect(Cart::query()->count())->toBe(1)
        ->and(CartItem::query()->firstOrFail()->qty)->toBe(3)
        ->and(collect(Cookie::getQueuedCookies())->map(fn ($cookie) => $cookie->getName())->all())->toContain('cart');
});

it('refuses a product without a cart mode even when the UI is bypassed (SF-41)', function (SalesMode $mode) {
    $product = sellable(['sales_mode' => $mode]);

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id])
        ->call('add')
        ->assertHasErrors('cart')
        ->assertNotDispatched('cart-updated');

    expect(CartItem::query()->count())->toBe(0);
})->with([SalesMode::Reservation, SalesMode::Inquiry, SalesMode::Showcase]);

it('refuses a quantity above the stock with an inline message', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 2]);

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id, 'showQty' => true])
        ->set('qty', 5)
        ->call('add')
        ->assertHasErrors('cart')
        ->assertSet('qty', 2);

    expect(CartItem::query()->count())->toBe(0);
});

it('merges a repeated add into the same cart row', function () {
    $product = sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 10]);
    $cart = Cart::factory()->create();

    $component = livewireWithCart(AddToCart::class, $cart, ['productId' => $product->id, 'showQty' => true]);
    $component->set('qty', 2)->call('add')->set('qty', 3)->call('add');

    expect($cart->items()->count())->toBe(1)->and($cart->items()->first()->qty)->toBe(5);
});

it('renders a disabled «Нет в наличии» button when nothing is left', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 0]);

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id])
        ->assertSee('Нет в наличии')
        ->assertSee('disabled', false)
        ->assertDontSee('wire:click="add"', false);
});

it('labels the button «Заказать (под заказ)» for backorder', function () {
    sales(['allow_backorder' => true]);
    $product = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 0]);

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id])
        ->assertSee('Заказать (под заказ)');
});

it('adds from a product card through the plain form endpoint (JSON and no-JS redirect)', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart]);

    $this->postJson(route('cart.add'), ['product_id' => $product->id])
        ->assertOk()
        ->assertJson(['message' => 'Товар добавлен в корзину'])
        ->assertCookie('cart');

    $this->from(route('catalog.index'))->post(route('cart.add'), ['product_id' => $product->id])
        ->assertRedirect(route('catalog.index'))
        ->assertSessionHas('cart_flash');

    expect((int) CartItem::query()->sum('qty'))->toBe(2);
});

it('rejects a non cart product on the card endpoint', function () {
    $product = sellable(['sales_mode' => SalesMode::Reservation]);

    $this->postJson(route('cart.add'), ['product_id' => $product->id])->assertStatus(422)->assertJsonStructure(['message']);
    $this->from(route('catalog.index'))->post(route('cart.add'), ['product_id' => $product->id])->assertSessionHas('cart_error');

    expect(CartItem::query()->count())->toBe(0);
});

it('renders the card button as a form and the product page with the Livewire component', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart, 'name' => 'Лампа настольная']);

    $this->get(route('catalog.index'))->assertOk()
        ->assertSee('action="'.route('cart.add').'"', false)
        ->assertDontSee('storefront.add-to-cart', false);

    $this->get(route('product.show', $product->slug))->assertOk()
        ->assertSee('data-action="cart"', false)
        ->assertSee('wire:click="add"', false)
        ->assertSee('storefront.add-to-cart', false);
});

it('keeps the shop phone in showcase mode', function () {
    $product = sellable(['sales_mode' => SalesMode::Showcase]);
    app(GeneralSettings::class)->fill(['phones' => ['+7 900 000-00-00']])->save();

    $this->get(route('product.show', $product->slug))->assertOk()->assertSee('data-showcase-phone', false)->assertDontSee('wire:click="add"', false);
});
