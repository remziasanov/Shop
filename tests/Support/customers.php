<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Mail\CustomerCodeMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A confirmed customer in «Розница» with the factory password.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeCustomer(array $attributes = []): Customer
{
    return Customer::factory()->create($attributes);
}

/**
 * Puts a customer into the test session the way a real sign-in does: the guard's session key and the `auth_version`
 * the middleware compares (CA-31). It does not touch the default guard, so a staff session set up with actingAs() stays
 * independent.
 */
function signInCustomer(TestCase $test, Customer $customer): Customer
{
    $test->withSession([
        Auth::guard('customer')->getName() => $customer->id,
        CustomerAuthService::SESSION_VERSION_KEY => $customer->auth_version,
    ]);

    return $customer;
}

/**
 * Puts a staff member into the test session (the `web` guard's session key), so the sign-in survives nextRequest()
 * like a real browser session does.
 */
function signInStaff(TestCase $test, User $user): User
{
    $test->withSession([Auth::guard('web')->getName() => $user->id]);

    return $user;
}

/**
 * Starts the next request with a fresh guard: a real request never sees the user object of the previous one.
 */
function nextRequest(): void
{
    Auth::forgetGuards();
}

/**
 * The code in the latest queued code mail (call Mail::fake() first).
 */
function lastSentCode(?VerificationPurpose $purpose = null): string
{
    $mails = Mail::queued(CustomerCodeMail::class, fn (CustomerCodeMail $mail): bool => $purpose === null || $mail->purpose === $purpose);

    expect($mails)->not->toBeEmpty();

    /** @var CustomerCodeMail $last */
    $last = $mails->last();

    return $last->code;
}

/**
 * A fill-time token of a form that was rendered `$secondsAgo` seconds ago (FormGuard).
 */
function formToken(int $secondsAgo = 30): string
{
    return Crypt::encryptString((string) now()->subSeconds($secondsAgo)->getTimestamp());
}

/**
 * A valid registration request body.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationBody(array $overrides = []): array
{
    return array_merge([
        'name' => 'Иван Тестов',
        'email' => 'ivan.test@example.com',
        'phone' => '8 (900) 123-45-67',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
        'consent' => '1',
        'website' => '',
        'form_token' => formToken(),
    ], $overrides);
}
