<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Mail\CustomerCodeMail;
use App\Services\Customers\CustomerAuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->customer = makeCustomer(['email' => 'reset.owner@example.com']);
});

function startReset(string $email = 'reset.owner@example.com'): void
{
    test()->post(route('customer.password.send-code'), ['email' => $email])->assertRedirect(route('customer.password.code'));
}

it('resets the password by code: address, code, new password (CA-07)', function (): void {
    startReset();
    $code = lastSentCode(VerificationPurpose::PasswordReset);

    $this->post(route('customer.password.verify'), ['code' => $code])->assertRedirect(route('customer.password.reset'));
    $this->get(route('customer.password.reset'))->assertOk()->assertSee('Новый пароль');

    $this->post(route('customer.password.update'), ['password' => 'fresh-password-9', 'password_confirmation' => 'fresh-password-9'])
        ->assertRedirect(route('account'));

    $fresh = $this->customer->fresh();
    expect(Hash::check('fresh-password-9', $fresh->password))->toBeTrue()
        ->and($fresh->auth_version)->toBe(2);
    $this->assertAuthenticatedAs($fresh, 'customer');
    expect(session(CustomerAuthService::SESSION_VERSION_KEY))->toBe(2);
});

it('answers the same for an unknown address and sends nothing (CA-05)', function (): void {
    startReset('nobody@example.com');

    expect(session('status'))->toBe(__('account.reset.code_sent'));
    Mail::assertNothingQueued();

    $this->post(route('customer.password.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
});

it('sends no reset code to a blocked account', function (): void {
    $this->customer->forceFill(['is_blocked' => true])->save();

    startReset();

    Mail::assertNothingQueued();
});

it('keeps the new-password form closed without a verified code', function (): void {
    $this->get(route('customer.password.reset'))->assertRedirect(route('customer.password.forgot'));
    $this->post(route('customer.password.update'), ['password' => 'fresh-password-9', 'password_confirmation' => 'fresh-password-9'])
        ->assertRedirect(route('customer.password.forgot'));

    expect(Hash::check('fresh-password-9', $this->customer->fresh()->password))->toBeFalse();
});

it('closes the new-password form 10 minutes after the code was accepted (CA-07)', function (): void {
    startReset();
    $this->post(route('customer.password.verify'), ['code' => lastSentCode(VerificationPurpose::PasswordReset)]);

    $this->travel(11)->minutes();

    $this->post(route('customer.password.update'), ['password' => 'fresh-password-9', 'password_confirmation' => 'fresh-password-9'])
        ->assertRedirect(route('customer.password.forgot'));
    expect(Hash::check('fresh-password-9', $this->customer->fresh()->password))->toBeFalse();
});

it('lets the new-password form be used only once (CA-07)', function (): void {
    startReset();
    $this->post(route('customer.password.verify'), ['code' => lastSentCode(VerificationPurpose::PasswordReset)]);
    $this->post(route('customer.password.update'), ['password' => 'fresh-password-9', 'password_confirmation' => 'fresh-password-9'])->assertRedirect(route('account'));
    $this->post(route('customer.logout'));
    nextRequest();

    $this->post(route('customer.password.update'), ['password' => 'hijack-password-9', 'password_confirmation' => 'hijack-password-9'])
        ->assertRedirect(route('customer.password.forgot'));

    expect(Hash::check('hijack-password-9', $this->customer->fresh()->password))->toBeFalse();
});

it('applies the password rules to the new password and keeps the ticket for a retry', function (): void {
    startReset();
    $this->post(route('customer.password.verify'), ['code' => lastSentCode(VerificationPurpose::PasswordReset)]);

    $this->post(route('customer.password.update'), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->post(route('customer.password.update'), ['password' => 'reset.owner@example.com', 'password_confirmation' => 'reset.owner@example.com'])->assertSessionHasErrors('password');

    $this->post(route('customer.password.update'), ['password' => 'fresh-password-9', 'password_confirmation' => 'fresh-password-9'])->assertRedirect(route('account'));
});

it('rejects a wrong reset code', function (): void {
    startReset();

    $this->post(route('customer.password.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->get(route('customer.password.reset'))->assertRedirect(route('customer.password.forgot'));
});

it('does not use link tokens: the standard reset table stays empty (CA-07)', function (): void {
    startReset();

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Mail::assertQueued(CustomerCodeMail::class, 1);
});
