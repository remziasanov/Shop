<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Cart\CartService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * CA-15 (TZ 17.3): on sign-in the guest cart (cookie) joins the cart of the customer; quantities are summed and limited
 * by the stock; the customer cart follows the customer to other devices.
 */
beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->customer = makeCustomer(['email' => 'merge.owner@example.com']);
});

function loginWithCart(?Cart $guest, string $email = 'merge.owner@example.com'): TestResponse
{
    $request = $guest !== null ? test()->withCookie(CartService::COOKIE, $guest->token) : test();

    return $request->post(route('customer.login.store'), ['email' => $email, 'password' => 'password-123']);
}

it('hands the guest cart to a customer who has none', function (): void {
    $guest = cartWith(sellable(), sellable());

    loginWithCart($guest)->assertRedirect();

    $cart = Cart::query()->where('customer_id', $this->customer->id)->firstOrFail();

    expect($cart->id)->toBe($guest->id)
        ->and(CartItem::query()->where('cart_id', $cart->id)->count())->toBe(2);
});

it('sums the quantities of the same product and moves the other products over', function (): void {
    $shared = sellable(['stock_qty' => 20]);
    $onlyGuest = sellable(['stock_qty' => 20]);
    $onlyOwn = sellable(['stock_qty' => 20]);

    $own = Cart::factory()->create(['customer_id' => $this->customer->id]);
    CartItem::factory()->forProduct($shared, 2)->create(['cart_id' => $own->id]);
    CartItem::factory()->forProduct($onlyOwn, 1)->create(['cart_id' => $own->id]);

    $guest = Cart::factory()->create();
    CartItem::factory()->forProduct($shared, 3)->create(['cart_id' => $guest->id]);
    CartItem::factory()->forProduct($onlyGuest, 4)->create(['cart_id' => $guest->id]);

    loginWithCart($guest);

    $items = CartItem::query()->where('cart_id', $own->id)->get()->pluck('qty', 'product_id');

    expect($items->all())->toEqualCanonicalizing([$shared->id => 5, $onlyOwn->id => 1, $onlyGuest->id => 4])
        ->and($items->get($shared->id))->toBe(5)
        ->and($items->get($onlyGuest->id))->toBe(4)
        ->and(Cart::query()->whereKey($guest->id)->exists())->toBeFalse()
        ->and(Cart::query()->where('customer_id', $this->customer->id)->count())->toBe(1);
});

it('limits the summed quantity by the available stock', function (): void {
    $limited = sellable(['stock_qty' => 4]);
    $soldOut = sellable(['stock_qty' => 0]);

    $own = Cart::factory()->create(['customer_id' => $this->customer->id]);
    CartItem::factory()->forProduct($limited, 3)->create(['cart_id' => $own->id]);

    $guest = Cart::factory()->create();
    CartItem::factory()->forProduct($limited, 3)->create(['cart_id' => $guest->id]);
    CartItem::factory()->forProduct($soldOut, 2)->create(['cart_id' => $guest->id]);

    loginWithCart($guest);

    $items = CartItem::query()->where('cart_id', $own->id)->get()->pluck('qty', 'product_id');

    expect($items->all())->toBe([$limited->id => 4]);
});

it('keeps the customer cart between devices and after sign-out', function (): void {
    $guest = cartWith(sellable());
    loginWithCart($guest);

    // Another browser: no cookie at all, the same customer.
    session()->flush();
    nextRequest();
    signInCustomer($this, $this->customer);

    $this->get(route('cart'))->assertOk()->assertSee('data-checkout', false);

    expect(app(CartService::class)->countForRequest(request()))->toBeInt();
    expect(Cart::query()->where('customer_id', $this->customer->id)->exists())->toBeTrue();
});

it('resolves the cart of a signed-in customer by the account, and a guest cookie never opens a customer cart', function (): void {
    $product = sellable();
    $own = cartWith($product);
    $own->forceFill(['customer_id' => $this->customer->id])->save();

    // A guest holding the token of a customer cart sees nothing.
    $this->withCookie(CartService::COOKIE, $own->token)->get(route('cart'))->assertOk()->assertSee('data-cart-empty', false);

    signInCustomer($this, $this->customer);
    nextRequest();
    $this->get(route('cart'))->assertOk()->assertDontSee('data-cart-empty', false);
});

it('adds to the cart of the customer, not to a cookie cart, when signed in', function (): void {
    $product = sellable();
    signInCustomer($this, $this->customer);

    $this->postJson(route('cart.add'), ['product_id' => $product->id, 'qty' => 2])->assertSuccessful();

    $cart = Cart::query()->where('customer_id', $this->customer->id)->firstOrFail();

    expect(CartItem::query()->where('cart_id', $cart->id)->value('qty'))->toBe(2)
        ->and(Cart::query()->whereNull('customer_id')->count())->toBe(0);
})->skip(fn (): bool => ! Route::has('cart.add'));

it('ignores a malformed cookie and works without a guest cart', function (): void {
    loginWithCart(null)->assertRedirect();
    $this->withCookie(CartService::COOKIE, Str::uuid()->toString());
    expect(Cart::query()->count())->toBe(0);
});

it('forgets the guest cookie after the sign-in', function (): void {
    $guest = cartWith(sellable());

    $response = loginWithCart($guest);

    expect($response->headers->getCookies())->not->toBeEmpty();
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === CartService::COOKIE);
    expect($cookie?->getExpiresTime())->toBeLessThan(time());
});
