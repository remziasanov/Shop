<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\CustomerThrottledException;
use App\Mail\CustomerCodeGuessingMail;
use App\Mail\CustomerCodeMail;
use App\Models\CustomerVerificationCode;
use App\Services\Customers\VerificationCodeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Mail::fake();
    $this->codes = app(VerificationCodeService::class);
    $this->email = 'code.owner@example.com';
});

/** Asks for a registration code and returns it (the mail is the only place the plain code exists). */
function requestCode(VerificationCodeService $codes, string $email, string $ip = '192.0.2.10', VerificationPurpose $purpose = VerificationPurpose::Register): string
{
    $codes->request($purpose, $email, $ip);

    return lastSentCode($purpose);
}

it('issues a 6-digit code and stores only its HMAC (CA-04)', function (): void {
    $code = requestCode($this->codes, $this->email);

    expect($code)->toMatch('/^\d{6}$/');

    $row = CustomerVerificationCode::query()->firstOrFail();
    expect($row->code_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->code_hash)->not->toContain($code)
        ->and($row->attempts)->toBe(0)
        ->and($row->purpose)->toBe(VerificationPurpose::Register)
        ->and($row->sent_at->diffInMinutes($row->expires_at))->toEqualWithDelta(15, 0.01);

    // The plain code is nowhere in the table: no column holds it.
    expect(DB::table('customer_verification_codes')->get()->map(fn ($r): string => json_encode($r))->implode(''))->not->toContain('"'.$code.'"');
});

it('queues the mail with the code and its validity (CA-25)', function (): void {
    $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.10');

    Mail::assertQueued(CustomerCodeMail::class, fn (CustomerCodeMail $mail): bool => $mail->hasTo($this->email)
        && $mail->purpose === VerificationPurpose::Login
        && $mail->ttlMinutes === 15
        && str_contains($mail->envelope()->subject, $mail->code));
});

it('accepts the right code once (single use)', function (): void {
    $code = requestCode($this->codes, $this->email);

    $row = $this->codes->verify(VerificationPurpose::Register, $this->email, $code, '192.0.2.10');
    expect($row->consumed_at)->not->toBeNull();

    expect(fn () => $this->codes->verify(VerificationPurpose::Register, $this->email, $code, '192.0.2.10'))
        ->toThrow(CustomerAuthException::class);
});

it('does not accept a code of another purpose or another address', function (): void {
    $code = requestCode($this->codes, $this->email);

    expect(fn () => $this->codes->verify(VerificationPurpose::Login, $this->email, $code, '192.0.2.10'))->toThrow(CustomerAuthException::class)
        ->and(fn () => $this->codes->verify(VerificationPurpose::Register, 'other@example.com', $code, '192.0.2.10'))->toThrow(CustomerAuthException::class);
});

it('expires after the ttl (CA-04)', function (): void {
    $code = requestCode($this->codes, $this->email);

    $this->travel(16)->minutes();

    expect(fn () => $this->codes->verify(VerificationPurpose::Register, $this->email, $code, '192.0.2.10'))
        ->toThrow(CustomerAuthException::class);
});

it('annuls the code after 5 wrong entries, even for the right code afterwards (CA-04)', function (): void {
    $code = requestCode($this->codes, $this->email);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        expect(fn () => $this->codes->verify(VerificationPurpose::Register, $this->email, $wrong, '192.0.2.10'))->toThrow(CustomerAuthException::class);
    }

    expect(fn () => $this->codes->verify(VerificationPurpose::Register, $this->email, $code, '192.0.2.10'))->toThrow(CustomerAuthException::class);
    expect(CustomerVerificationCode::query()->firstOrFail()->consumed_at)->not->toBeNull();
});

it('annuls the previous code when a new one is sent (CA-04)', function (): void {
    $first = requestCode($this->codes, $this->email);
    $this->travel(61)->seconds();
    $second = requestCode($this->codes, $this->email);

    expect(CustomerVerificationCode::query()->whereNull('consumed_at')->count())->toBe(1);

    // The first code is dead unless the random second one happens to be the same.
    if ($first !== $second) {
        expect(fn () => $this->codes->verify(VerificationPurpose::Register, $this->email, $first, '192.0.2.10'))->toThrow(CustomerAuthException::class);
    }

    $this->codes->verify(VerificationPurpose::Register, $this->email, $second, '192.0.2.10');
});

it('does not resend within 60 seconds (CA-04)', function (): void {
    requestCode($this->codes, $this->email);

    expect(fn () => $this->codes->request(VerificationPurpose::Register, $this->email, '192.0.2.10'))->toThrow(CustomerAuthException::class);
    Mail::assertQueuedCount(1);

    $this->travel(61)->seconds();
    $this->codes->request(VerificationPurpose::Register, $this->email, '192.0.2.10');
    Mail::assertQueuedCount(2);
});

it('applies the same pause and stores nothing for an address without an account (CA-05)', function (): void {
    $this->codes->request(VerificationPurpose::Login, 'nobody@example.com', '192.0.2.10', deliver: false);

    expect(CustomerVerificationCode::query()->count())->toBe(0);
    Mail::assertNothingQueued();
    expect(fn () => $this->codes->request(VerificationPurpose::Login, 'nobody@example.com', '192.0.2.10', deliver: false))->toThrow(CustomerAuthException::class);

    // An unknown address cannot enter a code either, with the same answer as a wrong code.
    expect(fn () => $this->codes->verify(VerificationPurpose::Login, 'nobody@example.com', '123456', '192.0.2.10'))->toThrow(CustomerAuthException::class);
});

it('limits code requests to 5 per hour per e-mail and 10 per hour per IP (CA-12)', function (): void {
    foreach (range(1, 5) as $_) {
        $this->codes->request(VerificationPurpose::Register, $this->email, '192.0.2.10');
        $this->travel(61)->seconds();
    }

    $thrown = null;

    try {
        $this->codes->request(VerificationPurpose::Register, $this->email, '192.0.2.10');
    } catch (CustomerThrottledException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()->and($thrown->retryAfter)->toBeGreaterThan(0);
    Mail::assertQueuedCount(5);

    // Ten requests of one IP for different addresses are fine, the eleventh is not.
    foreach (range(1, 10) as $i) {
        $this->codes->request(VerificationPurpose::Register, "ip.test{$i}@example.com", '192.0.2.77');
    }

    expect(fn () => $this->codes->request(VerificationPurpose::Register, 'ip.test11@example.com', '192.0.2.77'))->toThrow(CustomerThrottledException::class);
});

it('limits code entries to 20 per hour per IP (CA-12)', function (): void {
    foreach (range(1, 20) as $i) {
        expect(fn () => $this->codes->verify(VerificationPurpose::Login, "entry{$i}@example.com", '123456', '192.0.2.50'))->toThrow(CustomerAuthException::class);
    }

    expect(fn () => $this->codes->verify(VerificationPurpose::Login, 'entry21@example.com', '123456', '192.0.2.50'))->toThrow(CustomerThrottledException::class);
});

/** Burns 4 codes with 5 wrong entries each: 20 counted wrong entries of one requester. */
function burnTwentyEntries(VerificationCodeService $codes, string $email, string $ip, string $binding, bool $deliver = true): void
{
    foreach (range(1, 4) as $_) {
        $codes->request(VerificationPurpose::Login, $email, $ip, deliver: $deliver, binding: $binding);

        foreach (range(1, 5) as $__) {
            try {
                $codes->verify(VerificationPurpose::Login, $email, '000000', $ip, $binding);
            } catch (CustomerAuthException) {
            }
        }

        test()->travel(61)->seconds();
    }
}

it('locks the requester for 24 hours after 20 wrong entries per day and tells the owner (CA-12, SEC-04)', function (): void {
    makeCustomer(['email' => $this->email]);

    burnTwentyEntries($this->codes, $this->email, '192.0.2.100', 'session-a');

    expect($this->codes->lockedFor($this->email, '192.0.2.100'))->toBeGreaterThan(86000)
        ->and($this->codes->lockedFor($this->email, '192.0.2.101'))->toBe(0);
    Mail::assertQueued(CustomerCodeGuessingMail::class, fn (CustomerCodeGuessingMail $mail): bool => $mail->hasTo($this->email));

    // Requests and entries of that requester are refused while the lock holds, then work again.
    expect(fn () => $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.100'))->toThrow(CustomerThrottledException::class)
        ->and(fn () => $this->codes->verify(VerificationPurpose::Login, $this->email, '123456', '192.0.2.100'))->toThrow(CustomerThrottledException::class);

    $this->travel(25)->hours();
    expect($this->codes->lockedFor($this->email, '192.0.2.100'))->toBe(0);
    Cache::flush();
    $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.201');
});

it('locks the requester of an address without an account exactly like one with an account, and mails nobody (CA-05, CA-12)', function (): void {
    burnTwentyEntries($this->codes, 'ghost@example.com', '192.0.2.100', 'session-a', deliver: false);

    // No account: no code was ever issued, but every entry counted all the same, so the lock is the same as for a real
    // account (otherwise the lock would show whether an account exists); nobody is mailed.
    Mail::assertNotQueued(CustomerCodeGuessingMail::class);
    expect($this->codes->lockedFor('ghost@example.com', '192.0.2.100'))->toBeGreaterThan(86000)
        ->and($this->codes->lockedFor('ghost@example.com', '192.0.2.101'))->toBe(0);
});

it('counts a wrong entry without an open code for the requester only, so nobody else is locked (CA-05, SEC-04)', function (): void {
    foreach (range(1, 25) as $_) {
        try {
            $this->codes->verify(VerificationPurpose::Login, $this->email, '123456', '192.0.2.101');
        } catch (CustomerAuthException|CustomerThrottledException) {
        }
    }

    expect($this->codes->lockedFor($this->email, '192.0.2.101'))->toBeGreaterThan(0)
        ->and($this->codes->lockedFor($this->email, '192.0.2.102'))->toBe(0)
        ->and(RateLimiter::attempts('customer-code:wrong:'.sha1($this->email.'|192.0.2.102')))->toBe(0);
});

it('mails the owner about guessing at most once per address per day, and only for an existing account (CA-12)', function (): void {
    makeCustomer(['email' => $this->email]);

    // Two different requesters lock themselves on the same address.
    burnTwentyEntries($this->codes, $this->email, '192.0.2.100', 'session-a');
    $this->travel(2)->hours();
    Cache::forget('customer-code:day:'.sha1($this->email));
    RateLimiter::clear('customer-code:day:'.sha1($this->email));
    RateLimiter::clear('customer-code:email:'.sha1($this->email));
    burnTwentyEntries($this->codes, $this->email, '192.0.2.200', 'session-b');

    expect($this->codes->lockedFor($this->email, '192.0.2.200'))->toBeGreaterThan(0);
    Mail::assertQueued(CustomerCodeGuessingMail::class, 1);
});

it('lets an attacker lock only themselves: the victim can still request a reset code from another IP (SEC-04)', function (): void {
    makeCustomer(['email' => $this->email]);

    burnTwentyEntries($this->codes, $this->email, '192.0.2.100', 'attacker-session');
    expect($this->codes->lockedFor($this->email, '192.0.2.100'))->toBeGreaterThan(0);

    $this->travel(61)->seconds();
    Mail::fake();
    $this->codes->request(VerificationPurpose::PasswordReset, $this->email, '198.51.100.7', binding: 'victim-session');
    Mail::assertQueued(CustomerCodeMail::class, fn (CustomerCodeMail $mail): bool => $mail->purpose === VerificationPurpose::PasswordReset);
});

it('refuses the 11th code request for one address within 24 hours (SEC-04)', function (): void {
    foreach (range(1, 10) as $i) {
        $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.'.$i, binding: 'b'.$i);
        // The hourly limit is 5: move on so only the daily cap is under test.
        $this->travel(61)->minutes();
    }

    expect(fn () => $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.50'))->toThrow(CustomerThrottledException::class);
});

it('counts a request before it decides, so the counter shows the refused attempt too (SEC-03)', function (): void {
    foreach (range(1, VerificationCodeService::REQUESTS_PER_EMAIL_PER_HOUR) as $_) {
        $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.10', deliver: false);
        $this->travel(61)->seconds();
    }

    $key = 'customer-code:email:'.sha1($this->email);
    expect(RateLimiter::attempts($key))->toBe(5);

    expect(fn () => $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.10', deliver: false))->toThrow(CustomerThrottledException::class);
    expect(RateLimiter::attempts($key))->toBe(6);
});

it('deletes long expired codes', function (): void {
    requestCode($this->codes, $this->email);
    $this->travel(3)->days();

    expect($this->codes->purgeExpired(1))->toBe(1)
        ->and(CustomerVerificationCode::query()->count())->toBe(0);
});

it('does not spend the address quotas of a request refused by its own IP limit (SEC-03)', function (): void {
    foreach (range(1, VerificationCodeService::REQUESTS_PER_IP_PER_HOUR) as $i) {
        $this->codes->request(VerificationPurpose::Login, "other{$i}@example.com", '192.0.2.77', deliver: false);
    }

    $emailKey = 'customer-code:email:'.sha1($this->email);
    $dayKey = 'customer-code:day:'.sha1($this->email);

    foreach (range(1, 8) as $_) {
        expect(fn () => $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.77', deliver: false))->toThrow(CustomerThrottledException::class);
    }

    expect(RateLimiter::attempts($emailKey))->toBe(0)->and(RateLimiter::attempts($dayKey))->toBe(0);
});

it('fails closed when the lock of a counter cannot be taken (SEC-03)', function (): void {
    $key = 'customer-code:ip:192.0.2.55';
    $lock = Cache::lock('rate-limit:'.sha1($key), 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => $this->codes->request(VerificationPurpose::Login, $this->email, '192.0.2.55', deliver: false))->toThrow(CustomerThrottledException::class);
    } finally {
        $lock->release();
    }
});
