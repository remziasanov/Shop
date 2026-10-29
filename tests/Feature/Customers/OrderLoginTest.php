<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CatalogVersion;
use App\Services\Orders\OrderService;
use App\Settings\CustomerSettings;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CA-01, CG-12, CA-26 (TZ 17.3, 25.2): «order only after login» and the shop-wide «order without registration» switch
 * are enforced on the server for checkout, reservation and inquiry, and an order under an account keeps the customer
 * and a snapshot of their group.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->product = sellable();
    $this->inquiryProduct = sellable(['sales_mode' => SalesMode::Inquiry]);
});

/** Makes the current request the one of a signed-in customer (service tests call OrderService outside HTTP). */
function actAsCustomerRequest(TestCase $test, Customer $customer): void
{
    signInCustomer($test, $customer);
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->beginRequest();
}

function guestCheckout(bool $allowed): void
{
    $settings = app(CustomerSettings::class);
    $settings->allow_guest_checkout = $allowed;
    $settings->save();
}

it('lets a guest order by default (CA-01, NF-56)', function (): void {
    $order = app(OrderService::class)->createFromCart(cartWith($this->product), checkoutData());

    expect($order->exists)->toBeTrue()
        ->and($order->customer_id)->toBeNull()
        ->and($order->customer_group_id)->toBeNull()
        ->and($order->customer_group_name)->toBeNull();
});

it('refuses checkout, reservation and inquiry to a guest when guest checkout is off (CA-01)', function (): void {
    guestCheckout(false);

    expect(fn () => app(OrderService::class)->createFromCart(cartWith($this->product), checkoutData()))->toThrow(SalesException::class, 'войдите')
        ->and(fn () => app(OrderService::class)->createReservation($this->product, reservationData()))->toThrow(SalesException::class)
        ->and(fn () => app(OrderService::class)->createInquiry($this->inquiryProduct, inquiryData()))->toThrow(SalesException::class);

    expect(Order::query()->count())->toBe(0);
});

it('refuses a guest when the «Гость» group requires a login (CG-12)', function (): void {
    CustomerGroup::query()->where('code', 'guest')->update(['require_login_to_order' => true]);
    CatalogVersion::bump();

    expect(fn () => app(OrderService::class)->createFromCart(cartWith($this->product), checkoutData()))->toThrow(SalesException::class);
    expect(app(ViewerContext::class)->mayOrder())->toBeFalse();
});

it('lets a signed-in customer order although guests may not, and stores the customer and the group snapshot (CA-01, CA-26)', function (): void {
    guestCheckout(false);
    CustomerGroup::query()->where('code', 'guest')->update(['require_login_to_order' => true]);
    $group = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $customer = makeCustomer(['customer_group_id' => $group->id]);
    actAsCustomerRequest($this, $customer);

    $order = app(OrderService::class)->createFromCart(cartWith($this->product), checkoutData());

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer_group_id)->toBe($group->id)
        ->and($order->customer_group_name)->toBe('Опт');

    // A later change of the group does not touch the old order (CA-26).
    $customer->forceFill(['customer_group_id' => CustomerGroup::retail()->id])->save();
    expect($order->fresh()->customer_group_name)->toBe('Опт')->and($order->fresh()->customer_group_id)->toBe($group->id);
});

it('stores customer and group for a reservation and an inquiry too (CA-26)', function (): void {
    $customer = makeCustomer();
    actAsCustomerRequest($this, $customer);

    $reservation = app(OrderService::class)->createReservation($this->product, reservationData());
    $inquiry = app(OrderService::class)->createInquiry($this->inquiryProduct, inquiryData());

    expect($reservation->customer_id)->toBe($customer->id)
        ->and($reservation->customer_group_name)->toBe('Розница')
        ->and($inquiry->customer_id)->toBe($customer->id);
});

it('does not take a blocked customer for a signed-in one', function (): void {
    guestCheckout(false);
    actAsCustomerRequest($this, makeCustomer(['is_blocked' => true]));

    expect(fn () => app(OrderService::class)->createFromCart(cartWith($this->product), checkoutData()))->toThrow(SalesException::class);
});

it('sends a guest from the checkout page to the sign-in page (CA-01, CG-12)', function (): void {
    guestCheckout(false);
    $cart = cartWith($this->product);

    $this->withCookie('cart', $cart->token)->get(route('checkout'))->assertRedirect(route('customer.login'));
    $this->get(route('customer.login'))->assertSee('войдите в личный кабинет');
});

it('shows a sign-in link instead of the checkout button in the cart (CA-01)', function (): void {
    guestCheckout(false);
    $cart = cartWith($this->product);

    $this->withCookie('cart', $cart->token)->get(route('cart'))
        ->assertOk()
        ->assertSee('data-checkout-login', false)
        ->assertDontSee('data-checkout>', false);

    guestCheckout(true);
    $this->withCookie('cart', $cart->token)->get(route('cart'))->assertSee('data-checkout', false)->assertDontSee('data-checkout-login', false);
});

it('shows sign-in links instead of the reservation and inquiry buttons (CG-12)', function (): void {
    guestCheckout(false);

    $this->get('/product/'.$this->product->slug)
        ->assertOk()
        ->assertSee('data-action="reservation-login"', false)
        ->assertDontSee('data-action="reservation"', false);
    $this->get('/product/'.$this->inquiryProduct->slug)
        ->assertOk()
        ->assertSee('data-action="inquiry-login"', false)
        ->assertDontSee('data-action="inquiry"', false);
});

it('opens the checkout to a signed-in customer and fills in the contact data (CA-01, 17.7)', function (): void {
    guestCheckout(false);
    $customer = makeCustomer(['name' => 'Пётр Покупатель', 'phone' => '+79001112233', 'email' => 'peter.buyer@example.com']);
    signInCustomer($this, $customer);
    // CA-15: the cart of a signed-in customer is the one bound to the account, not the cookie cart.
    cartWith($this->product)->forceFill(['customer_id' => $customer->id])->save();

    $html = $this->get(route('checkout'))->assertOk()->getContent();

    // The state of the checkout component is in its Livewire snapshot.
    preg_match_all('/wire:snapshot="([^"]*)"/', (string) $html, $matches);
    $state = collect($matches[1])
        ->map(fn (string $raw): array => json_decode(html_entity_decode($raw), true) ?: [])
        ->first(fn (array $snapshot): bool => ($snapshot['memo']['name'] ?? '') === 'storefront.checkout-form');

    expect($state['data']['name'])->toBe('Пётр Покупатель')
        ->and($state['data']['phone'])->toBe('+79001112233')
        ->and($state['data']['email'])->toBe('peter.buyer@example.com');
});

it('gates the Livewire checkout on the server too (CA-01)', function (): void {
    guestCheckout(false);
    $cart = cartWith($this->product);
    resetFormGuard();

    Livewire::withCookie('cart', $cart->token);
    $component = Livewire::test(CheckoutForm::class);
    $this->travel(10)->seconds();
    fillCheckout($component)->call('submit')->assertHasErrors('form');

    expect(Order::query()->count())->toBe(0);
});
