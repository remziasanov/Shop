<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Mail\CustomerCodeMail;
use App\Models\Customer;
use App\Services\Customers\CustomerAuthService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->customer = makeCustomer(['email' => 'login.owner@example.com']);
});

function loginBody(array $overrides = []): array
{
    return array_merge(['email' => 'login.owner@example.com', 'password' => 'password-123'], $overrides);
}

it('shows the real sign-in page on the route customer.login (CA-06)', function (): void {
    $this->get(route('customer.login'))
        ->assertOk()
        ->assertSee('Вход в личный кабинет')
        ->assertSee('Войти по коду')
        ->assertSee(route('customer.password.forgot'), false);
});

it('signs in with the password, regenerates the session and stores the version (CA-06, CA-31)', function (): void {
    $before = session()->getId();

    $this->post(route('customer.login.store'), loginBody())->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($this->customer, 'customer');
    expect(session()->getId())->not->toBe($before)
        ->and(session(CustomerAuthService::SESSION_VERSION_KEY))->toBe(1)
        ->and($this->customer->fresh()->last_login_at)->not->toBeNull();
    $this->assertGuest('web');
});

it('returns to the page the visitor wanted before signing in', function (): void {
    $this->get(route('account'))->assertRedirect(route('customer.login'));

    $this->post(route('customer.login.store'), loginBody())->assertRedirect(route('account'));
});

it('answers a wrong password and an unknown address with the same message (CA-05)', function (): void {
    $wrongPassword = $this->post(route('customer.login.store'), loginBody(['password' => 'wrong-password']));
    $unknown = $this->post(route('customer.login.store'), loginBody(['email' => 'nobody@example.com']));

    $wrongPassword->assertSessionHasErrors('email');
    $unknown->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe(__('account.errors.credentials'));
    $this->assertGuest('customer');
});

it('locks the pair e-mail + IP for 15 minutes after 5 failures, even for the right password (CA-06, CA-12)', function (): void {
    foreach (range(1, 4) as $_) {
        $this->post(route('customer.login.store'), loginBody(['password' => 'wrong-password']))->assertSessionHasErrors('email');
    }

    $this->post(route('customer.login.store'), loginBody(['password' => 'wrong-password']))
        ->assertStatus(429)
        ->assertHeader('Retry-After', '900');

    $this->post(route('customer.login.store'), loginBody())->assertStatus(429);
    $this->assertGuest('customer');

    // Another address from the same IP is not locked; the lock ends after 15 minutes.
    $other = makeCustomer(['email' => 'other.owner@example.com']);
    $this->post(route('customer.login.store'), loginBody(['email' => $other->email]))->assertRedirect();

    $this->travel(16)->minutes();
    nextRequest();
    $this->post(route('customer.login.store'), loginBody())->assertRedirect();
});

it('allows 5 password attempts per minute (CA-12)', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post(route('customer.login.store'), loginBody());
        nextRequest();
        $this->post(route('customer.logout'));
    }

    $this->post(route('customer.login.store'), loginBody())->assertStatus(429);
});

it('does not sign in a blocked customer and gives a neutral message (CA-06)', function (): void {
    $this->customer->forceFill(['is_blocked' => true, 'blocked_reason' => 'internal note'])->save();

    $this->post(route('customer.login.store'), loginBody())->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Обратитесь в магазин')->not->toContain('internal note');
    $this->assertGuest('customer');
});

it('sends an unconfirmed customer with the right password to the confirmation (CA-03)', function (): void {
    $this->customer->forceFill(['email_verified_at' => null])->save();

    $this->post(route('customer.login.store'), loginBody())->assertRedirect(route('customer.confirm'));

    $this->assertGuest('customer');
    expect(lastSentCode(VerificationPurpose::Register))->toMatch('/^\d{6}$/');
});

it('keeps a pending deletion from a password sign-in (CA-16)', function (): void {
    $this->customer->forceFill(['deletion_requested_at' => now()])->save();

    $this->post(route('customer.login.store'), loginBody())->assertSessionHasErrors('email');
    $this->assertGuest('customer');
});

it('sends a sign-in code to a known address and answers the same for an unknown one (CA-05, CA-06)', function (): void {
    $known = $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com']);
    $unknown = $this->post(route('customer.login.send-code'), ['email' => 'nobody@example.com']);

    $known->assertRedirect(route('customer.login.code'));
    $unknown->assertRedirect(route('customer.login.code'));
    expect(session('status'))->toBe(__('account.login.code_sent'));

    Mail::assertQueuedCount(1);
    Mail::assertQueued(CustomerCodeMail::class, fn (CustomerCodeMail $mail): bool => $mail->hasTo('login.owner@example.com') && $mail->purpose === VerificationPurpose::Login);
});

it('signs in with the code and remembers nothing unless asked (CA-06)', function (): void {
    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com']);
    $code = lastSentCode(VerificationPurpose::Login);

    $this->post(route('customer.login.verify'), ['code' => $code])->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($this->customer, 'customer');
});

it('does not sign in with a wrong code, and an unknown address fails in the same way (CA-05)', function (): void {
    $this->post(route('customer.login.send-code'), ['email' => 'nobody@example.com']);
    $this->post(route('customer.login.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
    $unknownMessage = session('errors')->first('code');

    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com']);
    $this->post(route('customer.login.verify'), ['code' => '123456'])->assertSessionHasErrors('code');

    expect(session('errors')->first('code'))->toBe($unknownMessage);
    $this->assertGuest('customer');
});

it('sends no sign-in code to a blocked or unconfirmed account', function (): void {
    $this->customer->forceFill(['is_blocked' => true])->save();
    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com'])->assertRedirect(route('customer.login.code'));

    Mail::assertNothingQueued();
});

it('cancels a pending deletion when the customer signs in with a code (CA-16)', function (): void {
    $this->customer->forceFill(['deletion_requested_at' => now()])->save();
    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com']);

    $this->post(route('customer.login.verify'), ['code' => lastSentCode(VerificationPurpose::Login)])->assertRedirect(route('account'));

    expect($this->customer->fresh()->deletion_requested_at)->toBeNull();
});

it('limits sign-in code requests to 5 per hour per address with HTTP 429 (CA-12)', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com'])->assertRedirect();
        $this->travel(61)->seconds();
    }

    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com'])
        ->assertStatus(429)
        ->assertSee('Слишком много попыток');
});

it('refuses a second code request within 60 seconds', function (): void {
    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com'])->assertRedirect();
    $this->post(route('customer.login.send-code'), ['email' => 'login.owner@example.com'])->assertSessionHasErrors();
});

it('signs out and ends the session', function (): void {
    $this->post(route('customer.login.store'), loginBody());
    nextRequest();

    $this->post(route('customer.logout'))->assertRedirect(route('home'));

    $this->assertGuest('customer');
    nextRequest();
    $this->get(route('account'))->assertRedirect(route('customer.login'));
});

it('keeps a signed-in customer away from the sign-in pages', function (): void {
    signInCustomer($this, $this->customer);

    $this->get(route('customer.login'))->assertRedirect(route('account'));
    $this->get(route('customer.register'))->assertRedirect(route('account'));
});

it('shows the sign-in link to guests and the account menu to customers (CA-19)', function (): void {
    $this->get('/')->assertOk()->assertSee('data-account-login', false)->assertSee('data-account-bar', false);

    signInCustomer($this, $this->customer);
    $this->get('/')->assertOk()
        ->assertDontSee('data-account-login', false)
        ->assertSee('data-account-menu', false)
        ->assertSee($this->customer->name);
});

it('never exposes the password or the remember token', function (): void {
    expect(Customer::query()->first()->toArray())->not->toHaveKeys(['password', 'remember_token', 'blocked_reason']);
});
