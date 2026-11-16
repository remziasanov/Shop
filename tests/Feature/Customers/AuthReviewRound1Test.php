<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Models\Customer;
use App\Models\CustomerVerificationCode;
use App\Models\User;
use App\Services\Customers\CustomerAdminService;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\RegistrationData;
use App\Services\Sales\FormGuard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
 * Review of PR #51, round 1 (part R1): customer authentication.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

/**
 * Registers `$email` with a password, then signs in with it from a fresh session; returns what the visitor can see.
 *
 * @return array{register: string|null, login: string|null, error: string|null}
 */
function registerThenSignIn(string $email): array
{
    test()->travel(-30)->seconds();
    $token = app(FormGuard::class)->issueToken();
    test()->travel(30)->seconds();

    $register = test()->post(route('customer.register.store'), [
        'name' => 'Probe', 'email' => $email, 'phone' => '+7 900 111-22-33',
        'password' => 'Attacker-pass-123', 'password_confirmation' => 'Attacker-pass-123',
        'consent' => '1', 'website' => '', 'form_token' => $token,
    ]);
    $registerTarget = $register->headers->get('Location');

    test()->flushSession();
    $login = test()->from(route('customer.login'))->post(route('customer.login.store'), ['email' => $email, 'password' => 'Attacker-pass-123']);

    return [
        'register' => $registerTarget,
        'login' => $login->status().' '.$login->headers->get('Location'),
        'error' => $login->assertSessionHasErrors(['email' => __('account.errors.credentials')]) ? __('account.errors.credentials') : null,
    ];
}

it('answers a password sign-in after a registration the same for a new and for a registered address (SEC-06)', function (): void {
    Customer::factory()->create(['email' => 'victim@example.com']);

    $existing = registerThenSignIn('victim@example.com');
    $new = registerThenSignIn('nobody@example.com');

    expect($new)->toBe($existing)
        ->and($new['error'])->toBe(__('account.errors.credentials'));
});

it('stores no usable password on a new unconfirmed account and sets the chosen one on confirmation (SEC-06)', function (): void {
    $auth = app(CustomerAuthService::class);
    $auth->register(new RegistrationData('Иван', 'fresh@example.com', '+79001234567', 'chosen-pass-123', '192.0.2.10', 'browser-1'));

    $customer = Customer::query()->where('email', 'fresh@example.com')->firstOrFail();
    expect(Hash::check('chosen-pass-123', $customer->password))->toBeFalse();

    $auth->confirmRegistration('fresh@example.com', lastSentCode(VerificationPurpose::Register), '192.0.2.10', 'browser-1');

    expect(Hash::check('chosen-pass-123', $customer->fresh()->password))->toBeTrue();
});

it('also refuses a password sign-in to an unconfirmed account that still holds a real hash (SEC-06)', function (): void {
    $customer = Customer::factory()->create(['email' => 'legacy@example.com', 'email_verified_at' => null]);

    expect(fn () => app(CustomerAuthService::class)->authenticateByPassword('legacy@example.com', 'password-123', '192.0.2.10'))
        ->toThrow(CustomerAuthException::class, __('account.errors.credentials'));
    expect($customer->fresh()->email_verified_at)->toBeNull();
    Mail::assertNothingQueued();
});

/**
 * The same 28 requests for any address; returns the status of the last one (the resend after the lockout sequence).
 */
function codeSignInProbe(string $email): int
{
    test()->post(route('customer.login.send-code'), ['email' => $email]);

    for ($c = 0; $c < 4; $c++) {
        if ($c > 0) {
            test()->travel(61)->seconds();
            test()->from(route('customer.login.code'))->post(route('customer.login.resend'));
        }

        for ($i = 0; $i < 5; $i++) {
            test()->from(route('customer.login.code'))->post(route('customer.login.verify'), ['code' => '000000']);
        }
    }

    test()->travel(61)->seconds();

    return test()->from(route('customer.login.code'))->post(route('customer.login.resend'))->status();
}

it('ends the code sign-in sequence with the same status for an existing and a non-existing address (CA-05)', function (): void {
    Customer::factory()->create(['email' => 'victim@example.com']);

    $existing = codeSignInProbe('victim@example.com');
    // A new hour: the per-IP limits of the first run are over; the sequence itself is identical.
    $this->flushSession();
    $this->travel(2)->hours();
    $ghost = codeSignInProbe('ghost@example.com');

    expect($ghost)->toBe($existing)->and($existing)->toBe(429);
});

it('binds a code that staff resend to the session of the registration (SEC-04)', function (): void {
    $auth = app(CustomerAuthService::class);
    $auth->register(new RegistrationData('Жертва', 'owner@example.com', '+79001234567', 'victim-pass-123', '192.0.2.11', 'victim-session'));
    $this->travel(61)->seconds();

    $customer = Customer::query()->firstOrFail();
    app(CustomerAdminService::class)->resendConfirmationCode($customer, User::factory()->admin()->create(), '192.0.2.20');

    $row = CustomerVerificationCode::query()->where('email', 'owner@example.com')->orderByDesc('id')->firstOrFail();
    expect($row->session_hash)->toBe(hash('sha256', 'victim-session'));

    // An outsider's session cannot even try it; the owner's browser can confirm.
    expect(fn () => $auth->confirmRegistration('owner@example.com', lastSentCode(VerificationPurpose::Register), '192.0.2.99', 'attacker-session'))
        ->toThrow(CustomerAuthException::class);
    expect(CustomerVerificationCode::query()->find($row->id)->attempts)->toBe(0);

    $confirmed = $auth->confirmRegistration('owner@example.com', lastSentCode(VerificationPurpose::Register), '192.0.2.11', 'victim-session');
    expect($confirmed->email_verified_at)->not->toBeNull();
});

it('shares one current-password counter between the password change and the e-mail change (SEC-05)', function (): void {
    $customer = signInCustomer($this, makeCustomer());

    foreach (range(1, 3) as $_) {
        $this->post(route('customer.profile.password'), ['current_password' => 'wrong', 'password' => 'brand-new-pass-7', 'password_confirmation' => 'brand-new-pass-7'])
            ->assertSessionHasErrors('current_password');
    }

    foreach (range(1, 2) as $_) {
        $this->post(route('customer.email.request'), ['email' => 'new.address@example.com', 'email_current_password' => 'wrong'])
            ->assertSessionHasErrors('email_current_password');
    }

    // 5 wrong checks of the two forms together: the sixth is refused, even with the right password.
    $this->post(route('customer.email.request'), ['email' => 'new.address@example.com', 'email_current_password' => 'password-123'])->assertStatus(429);
    expect($customer->fresh()->email)->not->toBe('new.address@example.com');
});

it('caps the wrong current-password checks per customer per day (SEC-05)', function (): void {
    $customer = signInCustomer($this, makeCustomer());

    foreach (range(1, 4) as $round) {
        foreach (range(1, 5) as $_) {
            $this->post(route('customer.profile.password'), ['current_password' => 'wrong', 'password' => 'brand-new-pass-7', 'password_confirmation' => 'brand-new-pass-7']);
        }

        $this->travel(61)->seconds();
    }

    // 20 wrong checks used up the day; a minute later the right password is still refused.
    $this->post(route('customer.profile.password'), ['current_password' => 'password-123', 'password' => 'brand-new-pass-7', 'password_confirmation' => 'brand-new-pass-7'])->assertStatus(429);
    expect(Hash::check('password-123', $customer->fresh()->password))->toBeTrue()
        ->and(RateLimiter::attempts('customer-current-password:day:'.$customer->id))->toBeGreaterThanOrEqual(20);
});

it('does not use up the daily current-password checks with requests the minute limit refused (SEC-05)', function (): void {
    $customer = signInCustomer($this, makeCustomer());

    foreach (range(1, 25) as $_) {
        $this->post(route('customer.profile.password'), ['current_password' => 'wrong', 'password' => 'brand-new-pass-7', 'password_confirmation' => 'brand-new-pass-7']);
    }

    // Only the 5 checks that passed the minute limit are counted for the day.
    expect(RateLimiter::attempts('customer-current-password:day:'.$customer->id))->toBe(5);
});
