<?php

declare(strict_types=1);

use App\Models\Order;
use App\Settings\CustomerSettings;

/**
 * CA-19, CA-27 and the sign-in link of the cart page (TZ 17.3).
 */
beforeEach(function (): void {
    $this->withoutVite();
});

it('offers a guest to create a cabinet on the thank-you page, with the e-mail prefilled (CA-27)', function (): void {
    $order = Order::factory()->create(['customer_email' => 'guest.buyer@example.com']);

    $this->get(route('order.thanks', $order->uuid))
        ->assertOk()
        ->assertSee('data-create-account', false)
        ->assertSee('href="'.route('customer.register').'"', false)
        // NF-15: the address never travels in a URL (server logs, Referer), the page itself does not print it either.
        ->assertDontSee('guest.buyer', false)
        ->assertDontSee('email=', false);

    // The registration form of the same session is prefilled from the remembered order.
    $this->get(route('customer.register'))->assertOk()->assertSee('value="guest.buyer@example.com"', false);
});

it('does not prefill the registration form for another browser session or from a query string (CA-27, NF-15)', function (): void {
    Order::factory()->create(['customer_email' => 'guest.private@example.com']);

    $this->get(route('customer.register', ['email' => 'guest.private@example.com']))
        ->assertOk()
        ->assertDontSee('guest.private@example.com', false);
});

it('does not offer a cabinet without an e-mail, to a signed-in customer, for an account order, or when registration is off (CA-27)', function (): void {
    $withoutEmail = Order::factory()->create(['customer_email' => null]);
    $this->get(route('order.thanks', $withoutEmail->uuid))->assertDontSee('data-create-account', false);

    $order = Order::factory()->create(['customer_email' => 'x.buyer@example.com']);
    app(CustomerSettings::class)->fill(['registration_enabled' => false])->save();
    $this->get(route('order.thanks', $order->uuid))->assertDontSee('data-create-account', false);

    app(CustomerSettings::class)->fill(['registration_enabled' => true])->save();
    signInCustomer($this, makeCustomer());
    $this->get(route('order.thanks', $order->uuid))->assertDontSee('data-create-account', false);

    $own = Order::factory()->create(['customer_email' => 'own@example.com', 'customer_id' => makeCustomer()->id]);
    session()->flush();
    nextRequest();
    $this->get(route('order.thanks', $own->uuid))->assertDontSee('data-create-account', false);
});

it('hides the header and bottom-bar account items when registration is off, but keeps them for a signed-in customer (CA-19)', function (): void {
    app(CustomerSettings::class)->fill(['registration_enabled' => false])->save();

    $this->get(route('home'))->assertOk()->assertDontSee('data-account-login', false)->assertDontSee('data-account-bar', false);

    signInCustomer($this, makeCustomer(['name' => 'Остался Вошедшим']));
    $this->get(route('home'))->assertOk()->assertSee('data-account-menu', false)->assertSee('data-account-bar', false);
});

it('brings the customer back to the checkout after signing in from the cart page', function (): void {
    $this->get(route('customer.login', ['back' => 'checkout']))->assertOk();
    $customer = makeCustomer(['email' => 'back.to.cart@example.com']);

    $this->post(route('customer.login.store'), ['email' => $customer->email, 'password' => 'password-123'])->assertRedirect(route('checkout'));
});

it('ignores any other value of the back parameter', function (): void {
    $this->get(route('customer.login', ['back' => 'https://evil.example.com']))->assertOk();
    $customer = makeCustomer(['email' => 'no.open.redirect@example.com']);

    $this->post(route('customer.login.store'), ['email' => $customer->email, 'password' => 'password-123'])->assertRedirect(route('account'));
});

it('links the cart-page sign-in button with the back parameter', function (): void {
    sales(['stock_tracking' => true]);
    app(CustomerSettings::class)->fill(['allow_guest_checkout' => false])->save();
    $cart = cartWith(sellable());

    $this->withCookie('cart', $cart->token)->get(route('cart'))
        ->assertOk()
        ->assertSee(route('customer.login', ['back' => 'checkout']), false);
});
