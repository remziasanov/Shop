<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Storefront\Account\RegisterController;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAdminService;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\RegistrationData;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * CA-05: a registration on the address of an unconfirmed account must never let somebody else's password become the
 * password of the account the owner of the mailbox confirms. The data of a registration travels with its code and is
 * applied only when that code is entered from the session that asked for it.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->auth = app(CustomerAuthService::class);
    $this->email = 'victim@example.com';
});

function registrationFor(string $password, string $binding, string $name = 'Имя'): RegistrationData
{
    return new RegistrationData($name, 'victim@example.com', '+79001234567', $password, '192.0.2.10', $binding);
}

it('does not overwrite the stored account when the unconfirmed address is registered again', function (): void {
    $this->auth->register(registrationFor('victim-password-1', 'victim-session', 'Жертва'));
    $this->travel(61)->seconds();

    $this->auth->register(registrationFor('attacker-password-1', 'attacker-session', 'Атакующий'));

    $customer = Customer::query()->firstOrFail();
    expect($customer->name)->toBe('Жертва')
        ->and(Hash::check('victim-password-1', $customer->password))->toBeTrue()
        ->and(Hash::check('attacker-password-1', $customer->password))->toBeFalse();
});

it('refuses a code requested by an attacker session when the victim enters it, and keeps the victim password (CA-05)', function (): void {
    $this->auth->register(registrationFor('victim-password-1', 'victim-session', 'Жертва'));
    $this->travel(61)->seconds();
    // The attacker registers the same unconfirmed address: the new code goes to the victim's mailbox.
    $this->auth->register(registrationFor('attacker-password-1', 'attacker-session', 'Атакующий'));
    $codeInVictimMailbox = lastSentCode(VerificationPurpose::Register);

    // The victim types it on his confirm page: that session is not the one that asked for the code.
    expect(fn () => $this->auth->confirmRegistration($this->email, $codeInVictimMailbox, '192.0.2.11', 'victim-session'))
        ->toThrow(CustomerAuthException::class);

    $customer = Customer::query()->firstOrFail();
    expect($customer->email_verified_at)->toBeNull();

    // The victim asks for a code again: it carries HIS registration, and confirming it applies his password.
    $this->travel(61)->seconds();
    $this->auth->resendRegistrationCode($this->email, '192.0.2.11', 'victim-session');
    $this->auth->confirmRegistration($this->email, lastSentCode(VerificationPurpose::Register), '192.0.2.11', 'victim-session');

    $customer = Customer::query()->firstOrFail();
    expect($customer->email_verified_at)->not->toBeNull()
        ->and($customer->name)->toBe('Жертва')
        ->and(Hash::check('victim-password-1', $customer->password))->toBeTrue()
        ->and(Hash::check('attacker-password-1', $customer->password))->toBeFalse();
});

it('keeps the victim password when the attacker registered first and the victim registers later (CA-05)', function (): void {
    $this->auth->register(registrationFor('attacker-password-1', 'attacker-session', 'Атакующий'));
    $this->travel(61)->seconds();
    $this->auth->register(registrationFor('victim-password-1', 'victim-session', 'Жертва'));
    $victimCode = lastSentCode(VerificationPurpose::Register);
    $this->travel(61)->seconds();
    // The attacker registers again before the victim has entered the code (it annuls the victim's code).
    $this->auth->register(registrationFor('attacker-password-2', 'attacker-session', 'Атакующий'));

    // The victim asks for a new code from his own session: the resent code carries the victim's data, not the attacker's.
    $this->travel(61)->seconds();
    $this->auth->resendRegistrationCode($this->email, '192.0.2.11', 'victim-session');
    expect(fn () => $this->auth->confirmRegistration($this->email, $victimCode, '192.0.2.11', 'victim-session'))->toThrow(CustomerAuthException::class);
    $this->auth->confirmRegistration($this->email, lastSentCode(VerificationPurpose::Register), '192.0.2.11', 'victim-session');

    $customer = Customer::query()->firstOrFail();
    expect($customer->name)->toBe('Жертва')
        ->and(Hash::check('victim-password-1', $customer->password))->toBeTrue()
        ->and(Hash::check('attacker-password-1', $customer->password))->toBeFalse()
        ->and(Hash::check('attacker-password-2', $customer->password))->toBeFalse();
});

it('applies the data of the registration whose code is entered, not an older pending one (CA-05)', function (): void {
    $this->auth->register(registrationFor('first-password-1', 'same-session', 'Первый'));
    $this->travel(61)->seconds();
    $this->auth->register(registrationFor('second-password-1', 'same-session', 'Второй'));

    $this->auth->confirmRegistration($this->email, lastSentCode(VerificationPurpose::Register), '192.0.2.10', 'same-session');

    $customer = Customer::query()->firstOrFail();
    expect($customer->name)->toBe('Второй')
        ->and(Hash::check('second-password-1', $customer->password))->toBeTrue()
        ->and(Hash::check('first-password-1', $customer->password))->toBeFalse();
});

it('does not accept the code without the binding of the session that asked for it', function (): void {
    $this->auth->register(registrationFor('victim-password-1', 'victim-session'));
    $code = lastSentCode(VerificationPurpose::Register);

    expect(fn () => $this->auth->confirmRegistration($this->email, $code, '192.0.2.10', null))->toThrow(CustomerAuthException::class);
});

it('refuses the code in a browser other than the one that registered (HTTP flow, CA-05)', function (): void {
    $this->post(route('customer.register.store'), registrationBody(['email' => 'http.owner@example.com']))->assertRedirect();
    $code = lastSentCode(VerificationPurpose::Register);

    // Another browser: it knows the pending address but holds no binding of the registration.
    $this->flushSession();
    $this->withSession([RegisterController::PENDING_EMAIL_KEY => 'http.owner@example.com', RegisterController::BINDING_KEY => 'some-other-browser']);

    $this->post(route('customer.confirm.store'), ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest('customer');
    expect(Customer::query()->firstOrFail()->email_verified_at)->toBeNull();
});

it('lets a staff resend confirm only the pending registration, never a password set by somebody else (CA-05, D-108)', function (): void {
    $this->auth->register(registrationFor('attacker-password-1', 'attacker-session', 'Атакующий'));
    $this->travel(61)->seconds();
    $this->auth->register(registrationFor('victim-password-1', 'victim-session', 'Жертва'));
    $this->travel(61)->seconds();

    // The victim's mail is late; staff press «Отправить код подтверждения».
    $customer = Customer::query()->firstOrFail();
    app(CustomerAdminService::class)
        ->resendConfirmationCode($customer, User::factory()->admin()->create(), '192.0.2.20');
    $staffCode = lastSentCode(VerificationPurpose::Register);

    // The attacker's session cannot use it; the victim's can, and it applies the victim's own data.
    expect(fn () => $this->auth->confirmRegistration($this->email, $staffCode, '192.0.2.12', 'attacker-session'))
        ->toThrow(CustomerAuthException::class);

    $this->travel(61)->seconds();
    app(CustomerAdminService::class)
        ->resendConfirmationCode($customer->fresh(), User::factory()->admin()->create(), '192.0.2.20');
    $this->auth->confirmRegistration($this->email, lastSentCode(VerificationPurpose::Register), '192.0.2.11', 'victim-session');

    $customer = Customer::query()->firstOrFail();
    expect($customer->email_verified_at)->not->toBeNull()
        ->and(Hash::check('victim-password-1', $customer->password))->toBeTrue()
        ->and(Hash::check('attacker-password-1', $customer->password))->toBeFalse();
});
