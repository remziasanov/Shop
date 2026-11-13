<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Services\Access\ViewerContext;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderService;
use App\Services\Promo\PromoLine;
use App\Services\Promo\PromoResult;
use App\Services\Promo\PromoService;
use App\Services\Promo\PromoViewer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A promo line; prices are kopecks. Category / brand / product only matter for scopes.
 */
function pline(int $unitPrice, int $qty = 1, ?int $old = null, ?int $category = null, ?string $brand = null, int $product = 1, ?int $key = null): PromoLine
{
    return new PromoLine($key ?? $product, $product, null, $category, $brand, $qty, $unitPrice, $old);
}

function guestPromoViewer(?string $email = null, ?string $phone = null): PromoViewer
{
    return new PromoViewer(guestGroup()->id, null, false, $email, $phone);
}

function retailPromoViewer(?Customer $customer = null, bool $verified = true): PromoViewer
{
    return new PromoViewer(CustomerGroup::retail()->id, $customer?->id, $verified);
}

/**
 * @param  list<PromoLine>  $lines
 */
function evaluatePromo(array $lines, PromoCode|string $code, ?PromoViewer $viewer = null, ?DateTimeInterface $now = null): PromoResult
{
    return app(PromoService::class)->evaluate($lines, $code, $viewer ?? guestPromoViewer(), $now === null ? null : Carbon::instance($now));
}

/**
 * A guest cart with the product (and the promo code on it).
 */
function promoCart(Product $product, int $qty = 1, ?string $code = null): Cart
{
    $cart = Cart::factory()->create();
    CartItem::factory()->forProduct($product, $qty)->create(['cart_id' => $cart->id]);

    if ($code !== null) {
        $cart->forceFill(['promo_code' => $code])->save();
    }

    return $cart;
}

function promoCheckout(Cart $cart, string $delivery = 'samovyvoz', ?int $linkId = null, ?string $email = 'ivan@example.com', string $phone = '8 (900) 123-45-67'): Order
{
    $data = checkoutData($delivery);

    return app(OrderService::class)->createFromCart($cart, new CheckoutData(
        $data->name, $phone, $email, $data->deliveryKey, $data->address, $data->paymentKey, null, true, '127.0.0.1', 'Pest', false, $linkId,
    ));
}

/**
 * Makes the following service calls of the test run as the signed-in customer (as a real request with a session would).
 */
function promoSignIn(TestCase $test, Customer $customer): void
{
    signInCustomer($test, $customer);
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->actingAs(null);
    app(ViewerContext::class)->beginRequest();
}

function promoLink(array $attributes = []): PromoLink
{
    return PromoLink::factory()->create($attributes);
}
