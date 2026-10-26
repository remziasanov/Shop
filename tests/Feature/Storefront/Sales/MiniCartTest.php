<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Livewire\Storefront\AddToCart;
use App\Livewire\Storefront\MiniCart;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Services\Sales\CartAvailability;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
});

it('shows the cart icon in the header and the bottom bar when the shop mode includes the cart (SF-01, SF-03)', function () {
    $this->get(route('home'))->assertOk()
        ->assertSee('aria-label="Корзина, товаров: 0"', false)
        ->assertSee('grid-cols-3', false);
});

it('hides the cart everywhere when no mode allows it (SF-01)', function () {
    sales(['default_mode' => SalesMode::Inquiry]);

    $this->get(route('home'))->assertOk()
        ->assertDontSee('Корзина, товаров', false)
        ->assertDontSee('cartToast', false)
        ->assertSee('grid-cols-2', false);
});

it('shows the cart when only a category or a product has a cart mode', function () {
    sales(['default_mode' => SalesMode::Inquiry]);
    $this->get(route('home'))->assertDontSee('Корзина, товаров', false);

    $category = Category::factory()->create(['sales_mode' => SalesMode::Both]);
    $this->get(route('home'))->assertSee('Корзина, товаров', false);

    $category->delete();
    $this->get(route('home'))->assertDontSee('Корзина, товаров', false);

    Product::factory()->inCategory(Category::factory()->create())->create(['sales_mode' => SalesMode::Cart]);
    $this->get(route('home'))->assertSee('Корзина, товаров', false);
});

it('caches the flag', function () {
    expect(app(CartAvailability::class)->enabled())->toBeTrue()
        ->and(Cache::has(CartAvailability::CACHE_KEY))->toBeTrue();

    sales(['default_mode' => SalesMode::Showcase]);

    expect(Cache::has(CartAvailability::CACHE_KEY))->toBeFalse()
        ->and(app(CartAvailability::class)->enabled())->toBeFalse();
});

it('counts the total quantity and refreshes on cart-updated (SF-01)', function () {
    $cart = cartWith(sellable(['sales_mode' => SalesMode::Cart]), sellable(['sales_mode' => SalesMode::Cart]));
    $cart->items()->first()->update(['qty' => 4]);

    livewireWithCart(MiniCart::class, $cart)
        ->assertSee('Корзина, товаров: 5')
        ->assertSeeHtml('data-cart-count');

    livewireWithCart(MiniCart::class, $cart, ['variant' => 'bar'])->assertSee('Корзина, товаров: 5')->assertSeeHtml('<li');
});

it('updates the counter after adding from the product page', function () {
    $product = sellable(['sales_mode' => SalesMode::Cart]);
    $cart = Cart::factory()->create();

    livewireWithCart(AddToCart::class, $cart, ['productId' => $product->id])->call('add');

    livewireWithCart(MiniCart::class, $cart)->dispatch('cart-updated')->assertSee('Корзина, товаров: 1');
});
