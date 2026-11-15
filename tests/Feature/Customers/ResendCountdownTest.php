<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Mail\CustomerCodeMail;
use App\Services\Customers\VerificationCodeService;
use App\Settings\CustomerSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CA-04: the resend button of every code page is disabled with a countdown until the server allows the next request.
 * The remaining seconds come from the same RateLimiter key as the pause itself.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

/** The `seconds` the page hands to the Alpine countdown. */
function countdownSeconds(TestResponse $response): int
{
    // @js() writes the quotes of the JSON as ", so the attribute stays valid.
    $found = preg_match('/resendCountdown\(.*?seconds\\\\u0022:(\d+)/', (string) $response->getContent(), $m);
    expect($found)->toBe(1);

    return (int) $m[1];
}

/**
 * Starts the flow of a code page with a code just sent.
 *
 * @return array{0: string, 1: string, 2: VerificationPurpose} page URL, resend URL, purpose
 */
function codePage(TestCase $test, string $kind): array
{
    switch ($kind) {
        case 'registration':
            $test->post(route('customer.register.store'), registrationBody());

            return [route('customer.confirm'), route('customer.confirm.resend'), VerificationPurpose::Register];
        case 'login code':
            makeCustomer(['email' => 'cd.login@example.com']);
            $test->post(route('customer.login.send-code'), ['email' => 'cd.login@example.com']);

            return [route('customer.login.code'), route('customer.login.resend'), VerificationPurpose::Login];
        case 'password reset':
            makeCustomer(['email' => 'cd.reset@example.com']);
            $test->post(route('customer.password.send-code'), ['email' => 'cd.reset@example.com']);

            return [route('customer.password.code'), route('customer.password.resend'), VerificationPurpose::PasswordReset];
        case 'e-mail change':
            signInCustomer($test, makeCustomer(['email' => 'cd.change@example.com']));
            $test->post(route('customer.email.request'), ['email' => 'cd.new@example.com']);

            return [route('customer.email.confirm'), route('customer.email.confirm.resend'), VerificationPurpose::ChangeEmail];
        default:
            signInCustomer($test, makeCustomer(['email' => 'cd.delete@example.com']));
            $test->post(route('customer.security.delete.request'));

            return [route('customer.security.delete'), route('customer.security.delete.resend'), VerificationPurpose::DeleteAccount];
    }
}

dataset('code pages', ['registration', 'login code', 'password reset', 'e-mail change', 'account deletion']);

it('renders the resend button with the Alpine countdown right after a code was sent', function (string $kind): void {
    [$page, $resend] = codePage($this, $kind);
    $limit = app(CustomerSettings::class)->code_resend_seconds;

    $response = $this->get($page)->assertOk();

    expect(countdownSeconds($response))->toBeGreaterThan(0)->toBeLessThanOrEqual($limit);
    $response
        ->assertSee('x-data="resendCountdown(', false)
        // TD-15: aria-disabled and a blocked submit keep the button in the Tab order; `disabled` would remove it.
        ->assertSee('x-bind:aria-disabled="left > 0', false)
        ->assertSee('x-on:submit="if (left > 0) $event.preventDefault()"', false)
        ->assertDontSee('x-bind:disabled', false)
        ->assertSee('aria-live="polite"', false)
        ->assertSee('action="'.$resend.'"', false)
        // Without JavaScript the button stays usable: it is not disabled in the HTML.
        ->assertDontSee('disabled=""', false)
        ->assertSee('Отправить код ещё раз');
})->with('code pages');

it('renders 0 when the pause is over', function (string $kind): void {
    [$page] = codePage($this, $kind);

    $this->travel(app(CustomerSettings::class)->code_resend_seconds + 1)->seconds();

    expect(countdownSeconds($this->get($page)->assertOk()))->toBe(0);
})->with('code pages');

it('sends a new code after the pause and restarts the countdown', function (string $kind): void {
    [$page, $resend, $purpose] = codePage($this, $kind);
    $limit = app(CustomerSettings::class)->code_resend_seconds;

    $this->travel($limit + 1)->seconds();
    $this->from($page)->post($resend)->assertRedirect($page)->assertSessionHasNoErrors()->assertSessionHas('status', 'Новый код отправлен.');
    Mail::assertQueued(CustomerCodeMail::class, 2);
    expect(lastSentCode($purpose))->toMatch('/^\d{6}$/');

    expect(countdownSeconds($this->get($page)))->toBeGreaterThan($limit - 5)->toBeLessThanOrEqual($limit);
})->with('code pages');

it('re-renders the page with the countdown when the resend comes too early', function (string $kind): void {
    [$page, $resend] = codePage($this, $kind);

    $this->travel(10)->seconds();
    $this->from($page)->post($resend)->assertRedirect($page);
    Mail::assertQueued(CustomerCodeMail::class, 1);

    $response = $this->get($page)->assertOk()->assertSee('Новый код можно запросить через');

    expect(countdownSeconds($response))->toBeGreaterThan(0)->toBeLessThanOrEqual(app(CustomerSettings::class)->code_resend_seconds - 10);
    $response->assertDontSee('aria-invalid="true"', false);
})->with('code pages');

it('reports a too early request under the `resend` key, not the `code` field (TD-15)', function (): void {
    $codes = app(VerificationCodeService::class);
    $codes->request(VerificationPurpose::Login, 'svc.key@example.com', '192.0.2.40', deliver: false);

    try {
        $codes->request(VerificationPurpose::Login, 'svc.key@example.com', '192.0.2.40', deliver: false);
        $field = null;
    } catch (CustomerAuthException $e) {
        $field = $e->field;
    }

    expect($field)->toBe('resend');
});

it('follows the code_resend_seconds setting', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->code_resend_seconds = 120;
    $settings->save();

    [$page] = codePage($this, 'registration');

    expect(countdownSeconds($this->get($page)))->toBeGreaterThan(60)->toBeLessThanOrEqual(120);
});

it('has nothing to resend on a code page without a pending flow', function (): void {
    $this->get(route('customer.login.code'))->assertRedirect(route('customer.login'));
    $this->post(route('customer.login.resend'))->assertRedirect(route('customer.login'));
    $this->post(route('customer.password.resend'))->assertRedirect(route('customer.password.forgot'));
    $this->post(route('customer.confirm.resend'))->assertRedirect(route('customer.register'));
    Mail::assertNothingQueued();
});

it('hands out the same remaining seconds for an address without an account (CA-05)', function (): void {
    $this->post(route('customer.login.send-code'), ['email' => 'nobody.here@example.com']);

    $response = $this->get(route('customer.login.code'))->assertOk();

    expect(countdownSeconds($response))->toBeGreaterThan(0);
    Mail::assertNothingQueued();
});

it('reports the remaining pause per address and purpose', function (): void {
    $codes = app(VerificationCodeService::class);

    expect($codes->resendAvailableIn('svc.owner@example.com', VerificationPurpose::Register))->toBe(0);

    $codes->request(VerificationPurpose::Register, 'svc.owner@example.com', '192.0.2.20');
    $limit = app(CustomerSettings::class)->code_resend_seconds;

    expect($codes->resendAvailableIn('svc.owner@example.com', VerificationPurpose::Register))->toBeGreaterThan(0)->toBeLessThanOrEqual($limit)
        // The key is case-insensitive like the request itself, and per purpose.
        ->and($codes->resendAvailableIn('SVC.Owner@example.com', VerificationPurpose::Register))->toBeGreaterThan(0)
        ->and($codes->resendAvailableIn('svc.owner@example.com', VerificationPurpose::Login))->toBe(0)
        ->and($codes->resendAvailableIn('other@example.com', VerificationPurpose::Register))->toBe(0);

    $this->travel(20)->seconds();
    expect($codes->resendAvailableIn('svc.owner@example.com', VerificationPurpose::Register))->toBeLessThanOrEqual($limit - 19);

    $this->travel($limit)->seconds();
    expect($codes->resendAvailableIn('svc.owner@example.com', VerificationPurpose::Register))->toBe(0);
});

it('hands out the same countdown for an existing account and for an address without one (CA-05)', function (): void {
    makeCustomer(['email' => 'cd.exists@example.com']);

    $this->post(route('customer.login.send-code'), ['email' => 'cd.exists@example.com']);
    $existing = countdownSeconds($this->get(route('customer.login.code')));

    $this->flushSession();
    $this->post(route('customer.login.send-code'), ['email' => 'cd.nobody@example.com']);
    $unknown = countdownSeconds($this->get(route('customer.login.code')));

    expect($existing)->toBeGreaterThan(0)->and(abs($existing - $unknown))->toBeLessThanOrEqual(1);
});

it('counts the hourly limit into the countdown and answers 429 through the resend route (CA-04, CA-12)', function (): void {
    [$page, $resend] = codePage($this, 'login code');
    $limit = app(CustomerSettings::class)->code_resend_seconds;

    // Four more requests after the pause each use up the hourly limit of 5 per address.
    foreach (range(1, VerificationCodeService::REQUESTS_PER_EMAIL_PER_HOUR - 1) as $_) {
        $this->travel($limit + 1)->seconds();
        $this->from($page)->post($resend)->assertSessionHasNoErrors();
    }

    // The pause is over, but the hourly limit is not: the countdown shows the longer wait and the server agrees.
    $this->travel($limit + 1)->seconds();
    expect(countdownSeconds($this->get($page)))->toBeGreaterThan($limit);
    $this->from($page)->post($resend)->assertStatus(429)->assertHeader('Retry-After');
});

it('does not spend the 5 per hour e-mail change limit of the customer on a request that is too early (TD-15)', function (): void {
    signInCustomer($this, makeCustomer(['email' => 'cd.spend@example.com']));
    $this->post(route('customer.email.request'), ['email' => 'cd.spend.new@example.com']);

    // Ten early clicks on the resend button would exhaust 5 changes per hour if they were counted.
    foreach (range(1, 10) as $_) {
        $this->from(route('customer.email.confirm'))->post(route('customer.email.confirm.resend'))->assertSessionHasErrors('resend');
    }

    $this->travel(app(CustomerSettings::class)->code_resend_seconds + 1)->seconds();
    $this->from(route('customer.email.confirm'))->post(route('customer.email.confirm.resend'))->assertSessionHasNoErrors();
});

it('reports the longest of the lock, the hourly limit and the pause as the resend wait (TD-15)', function (): void {
    $codes = app(VerificationCodeService::class);
    $limit = app(CustomerSettings::class)->code_resend_seconds;

    foreach (range(1, VerificationCodeService::REQUESTS_PER_EMAIL_PER_HOUR) as $_) {
        $codes->request(VerificationPurpose::Login, 'svc.hourly@example.com', '192.0.2.31', deliver: false);
        $this->travel($limit + 1)->seconds();
    }

    expect($codes->resendAvailableIn('svc.hourly@example.com', VerificationPurpose::Login))->toBeGreaterThan($limit);
});
