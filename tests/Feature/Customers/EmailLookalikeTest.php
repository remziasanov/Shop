<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\CheckoutForm;
use App\Mail\CustomerCodeMail;
use App\Models\Customer;
use App\Models\Order;
use App\Rules\CanonicalEmail;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\OrderLinker;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReservationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/**
 * Account takeover through look-alike addresses (CA-05, CA-06, CA-07, CA-14): `utf8mb4_unicode_ci` on MySQL treats
 * `victim@gmail.com` and `victim@gmáil.com` as equal, while the mailer delivers the second one to another domain.
 * Every address is reduced to a canonical ASCII form before it is stored or looked up.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

const LOOKALIKE = 'victim@gmáil.com';

it('validates addresses with the CanonicalEmail rule', function (): void {
    $passes = fn (string $value): bool => Validator::make(['email' => $value], ['email' => [new CanonicalEmail]])->passes();

    expect($passes('victim@gmail.com'))->toBeTrue()
        ->and($passes(LOOKALIKE))->toBeTrue()
        ->and($passes('victím@gmail.com'))->toBeFalse()
        ->and($passes('иван@example.com'))->toBeFalse()
        ->and($passes('not an address'))->toBeFalse();
});

it('says in Russian what is wrong with a non-Latin local part', function (): void {
    $this->post(route('customer.register.store'), registrationBody(['email' => 'victím@example.com']))
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('латиницей');
});

it('rejects a non-canonical address on registration, sign-in, reset, login code and e-mail change', function (): void {
    $customer = makeCustomer(['email' => 'owner@example.com']);

    $this->post(route('customer.register.store'), registrationBody(['email' => 'иван@example.com']))->assertSessionHasErrors('email');
    $this->post(route('customer.login.store'), ['email' => 'иван@example.com', 'password' => 'password-123'])->assertSessionHasErrors('email');
    $this->post(route('customer.login.send-code'), ['email' => 'иван@example.com'])->assertSessionHasErrors('email');
    $this->post(route('customer.password.send-code'), ['email' => 'иван@example.com'])->assertSessionHasErrors('email');

    signInCustomer($this, $customer);
    $this->post(route('customer.email.request'), ['email' => 'иван@example.com'])->assertSessionHasErrors('email');

    expect(Customer::query()->count())->toBe(1);
    Mail::assertNothingQueued();
});

it('stores the registered address in canonical form (upper case, international domain)', function (): void {
    $this->post(route('customer.register.store'), registrationBody(['email' => '  Ivan.Test@ПОЧТА.рф ']))->assertRedirect(route('customer.confirm'));

    $customer = Customer::query()->firstOrFail();
    expect($customer->email)->toBe('ivan.test@xn--80a1acny.xn--p1ai');

    Mail::assertQueued(CustomerCodeMail::class, fn ($mail): bool => $mail->hasTo('ivan.test@xn--80a1acny.xn--p1ai'));
    $this->assertDatabaseHas('customer_verification_codes', ['email' => 'ivan.test@xn--80a1acny.xn--p1ai']);
});

it('signs in with the address in any letter case', function (): void {
    makeCustomer(['email' => 'owner@example.com']);

    $this->post(route('customer.login.store'), ['email' => 'OWNER@Example.com', 'password' => 'password-123'])->assertRedirect();

    $this->assertAuthenticated('customer');
});

it('does not let a look-alike address request a reset or sign-in code for the real account', function (): void {
    makeCustomer(['email' => 'victim@gmail.com']);

    $this->post(route('customer.password.send-code'), ['email' => LOOKALIKE])->assertRedirect(route('customer.password.code'));
    $this->post(route('customer.login.send-code'), ['email' => LOOKALIKE])->assertRedirect(route('customer.login.code'));

    // The answer is the one for an unknown address: nothing is sent, nothing is stored.
    Mail::assertNothingQueued();
    expect(DB::table('customer_verification_codes')->count())->toBe(0);
});

it('does not link the guest requests of the real address to a look-alike account and vice versa', function (): void {
    $victim = makeCustomer(['email' => 'victim@gmail.com']);
    $lookalikeOrder = Order::factory()->create(['customer_email' => LOOKALIKE]);
    $realOrder = Order::factory()->create(['customer_email' => 'Victim@Gmail.com']);

    expect(app(OrderLinker::class)->link($victim))->toBe(1)
        ->and($realOrder->fresh()->customer_id)->toBe($victim->id)
        ->and($lookalikeOrder->fresh()->customer_id)->toBeNull();
});

it('links the requests of an international domain by its punycode form', function (): void {
    $owner = makeCustomer(['email' => 'ivan@xn--80a1acny.xn--p1ai']);
    $order = Order::factory()->create(['customer_email' => 'ivan@xn--80a1acny.xn--p1ai']);

    expect(app(OrderLinker::class)->link($owner))->toBe(1)
        ->and($order->fresh()->customer_id)->toBe($owner->id);
});

it('links past requests that are skipped, in more than one batch', function (): void {
    $victim = makeCustomer(['email' => 'victim@gmail.com']);
    // Rows the SQL comparison may return but the canonical comparison refuses must not stall the batches.
    Order::factory()->count(OrderLinker::BATCH + 3)->create(['customer_email' => 'victim@gmail.com']);

    expect(app(OrderLinker::class)->link($victim))->toBe(OrderLinker::BATCH + 3);
});

it('normalizes the address inside the services too, not only in the requests', function (): void {
    makeCustomer(['email' => 'owner@example.com']);
    $auth = app(CustomerAuthService::class);

    $auth->requestPasswordReset('  OWNER@example.com ', '192.0.2.5');

    Mail::assertQueued(CustomerCodeMail::class, fn ($mail): bool => $mail->hasTo('owner@example.com') && $mail->purpose === VerificationPurpose::PasswordReset);
    expect(fn () => $auth->requestLoginCode('иван@example.com', '192.0.2.5'))->toThrow(CustomerAuthException::class);
});

it('stores the canonical address of a checkout and refuses one without a canonical form (guest requests)', function (): void {
    $product = sellable();
    $cart = cartWith($product);
    resetFormGuard();

    Livewire::withCookie('cart', $cart->token);
    $component = Livewire::test(CheckoutForm::class);
    $this->travel(10)->seconds();
    fillCheckout($component, 'samovyvoz', ['email' => 'иван@example.com'])->call('submit')->assertHasErrors('email');
    expect(Order::query()->count())->toBe(0);

    fillCheckout($component, 'samovyvoz', ['email' => ' Ivan@GMÁIL.com '])->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->customer_email)->toBe('ivan@xn--gmil-6na.com');
});

it('canonicalizes the e-mail of a reservation at the service level', function (): void {
    $product = sellable();
    $data = reservationData();

    $order = app(OrderService::class)->createReservation($product, new ReservationData(
        $data->qty, $data->name, $data->phone, 'Buyer@Example.COM', null, null, true, '127.0.0.1', 'Pest',
    ));

    expect($order->customer_email)->toBe('buyer@example.com');
    expect(fn () => app(OrderService::class)->createReservation($product, new ReservationData(
        $data->qty, $data->name, $data->phone, 'иван@example.com', null, null, true, '127.0.0.1', 'Pest',
    )))->toThrow(SalesException::class);
});

it('keeps a look-alike registration from blocking the real owner (separate canonical addresses)', function (): void {
    $this->post(route('customer.register.store'), registrationBody(['email' => LOOKALIKE]))->assertRedirect(route('customer.confirm'));
    $this->post(route('customer.register.store'), registrationBody(['email' => 'victim@gmail.com']))->assertRedirect(route('customer.confirm'));

    expect(Customer::query()->pluck('email')->sort()->values()->all())->toBe(['victim@gmail.com', 'victim@xn--gmil-6na.com']);
});

it('refuses the look-alike flow on MySQL, where the collation equates the two addresses', function (): void {
    // Proof of the hazard: the database itself cannot tell the two apart.
    expect(Customer::query()->where('email', LOOKALIKE)->exists())->toBeFalse();
    makeCustomer(['email' => 'victim@gmail.com']);
    expect(Customer::query()->where('email', LOOKALIKE)->exists())->toBeTrue();

    $this->post(route('customer.password.send-code'), ['email' => LOOKALIKE])->assertRedirect();
    $this->post(route('customer.login.send-code'), ['email' => LOOKALIKE])->assertRedirect();
    $this->post(route('customer.login.store'), ['email' => LOOKALIKE, 'password' => 'password-123'])->assertSessionHasErrors('email');
    $this->assertGuest('customer');

    // A code that was issued for the victim cannot be used from the look-alike address either.
    $this->post(route('customer.password.send-code'), ['email' => 'victim@gmail.com'])->assertRedirect();
    $code = lastSentCode(VerificationPurpose::PasswordReset);
    $this->flushSession();
    $this->travel(61)->seconds();
    $this->post(route('customer.password.send-code'), ['email' => LOOKALIKE])->assertRedirect(route('customer.password.code'));
    $this->post(route('customer.password.verify'), ['code' => $code])->assertSessionHasErrors('code');

    // Guest requests of the look-alike are not the victim's.
    $order = Order::factory()->create(['customer_email' => LOOKALIKE]);
    expect(app(OrderLinker::class)->link(Customer::query()->where('email', 'victim@gmail.com')->firstOrFail()))->toBe(0)
        ->and($order->fresh()->customer_id)->toBeNull();

    // Registering the look-alike does not touch the victim's account and is no «already registered».
    $this->post(route('customer.register.store'), registrationBody(['email' => LOOKALIKE]))->assertRedirect(route('customer.confirm'));
    expect(Customer::query()->count())->toBe(2);
})->skip(fn (): bool => DB::getDriverName() !== 'mysql', 'MySQL only: needs the accent-insensitive utf8mb4_unicode_ci collation');
