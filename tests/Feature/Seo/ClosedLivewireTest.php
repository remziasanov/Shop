<?php

declare(strict_types=1);

use App\Livewire\Storefront\AddToCart;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\MiniCart;
use App\Livewire\Storefront\ReservationForm;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/seo.php';

beforeEach(function () {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

it('does not let a guest add to the cart through a Livewire snapshot taken before closing (SF-05, D-057)', function () {
    $product = sellable(['stock_qty' => 10]);

    $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])->map(fn (string $s) => html_entity_decode($s, ENT_QUOTES))->first(fn (string $s) => str_contains($s, 'productId'));
    expect($snapshot)->not->toBeNull();

    generalSettings(['shop_open' => false]);
    $this->get('/')->assertStatus(503);

    $this->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => new stdClass, 'calls' => [['method' => 'add', 'params' => [], 'metadata' => new stdClass]]]],
    ], ['X-Livewire' => '1'])->assertStatus(503);

    expect(DB::table('cart_items')->count())->toBe(0);
});

it('rejects add to cart in a mounted component after the shop closes', function () {
    $product = sellable();
    $component = Livewire\Livewire::test(AddToCart::class, ['productId' => $product->id]);

    generalSettings(['shop_open' => false]);

    $component->call('add')->assertStatus(503);
    expect(CartItem::query()->count())->toBe(0);
});

it('rejects the checkout submit after the shop closes and creates no order', function () {
    $cart = cartWith(sellable());
    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component);

    generalSettings(['shop_open' => false]);

    $component->call('submit')->assertStatus(503);
    expect(Order::query()->count())->toBe(0);
});

it('rejects reservation and inquiry submits after the shop closes', function () {
    $product = sellable(['stock_qty' => 5]);
    $contacts = ['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'comment' => '', 'consent' => true];

    $reservation = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id]);
    $inquiry = livewireWithCart(InquiryForm::class, null, ['productId' => $product->id]);
    $this->travel(5)->seconds();

    foreach ([$reservation, $inquiry] as $component) {
        foreach ($contacts as $key => $value) {
            $component->set($key, $value);
        }
    }

    generalSettings(['shop_open' => false]);

    $reservation->call('submit')->assertStatus(503);
    $inquiry->call('submit')->assertStatus(503);
    expect(Order::query()->count())->toBe(0)->and($product->fresh()->reserved_qty)->toBe(0);
});

it('does not mount storefront components while closed, for the cart page and the mini cart too', function (string $component) {
    $product = sellable();
    generalSettings(['shop_open' => false]);

    Livewire\Livewire::test($component, ['productId' => $product->id])->assertStatus(503);
})->with([AddToCart::class, CartPage::class, MiniCart::class, CheckoutForm::class, ReservationForm::class, InquiryForm::class]);

it('lets active staff use the components while closed', function () {
    $this->actingAs(User::factory()->admin()->create());
    generalSettings(['shop_open' => false]);
    $product = sellable();

    Livewire\Livewire::test(AddToCart::class, ['productId' => $product->id])->call('add');

    expect(CartItem::query()->count())->toBe(1);
});
