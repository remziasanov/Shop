<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PhoneVerificationError;
use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\PhoneVerificationException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\PhoneVerification\PhoneVerificationGate;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Support\ClientIpKey;
use App\Support\TelegramMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/** Security audit SEC-01, SEC-03, SEC-04 (D-198, D-199, D-200): abuse limits of the phone verification. */
beforeEach(function (): void {
    $this->settings = enablePhoneVerification(['daily_limit' => 1000]);
    $this->gateway = phoneStub();
    $this->pv = app(PhoneVerificationService::class);
    $this->purpose = PhoneVerificationPurpose::GuestCheckout;
});

afterEach(fn () => Carbon::setTestNow());

function abusePhone(int $i): string
{
    return '+7999100'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
}

function expectAbuseError(Closure $call, PhoneVerificationError $reason): void
{
    try {
        $call();
    } catch (PhoneVerificationException $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException('Expected PhoneVerificationException '.$reason->value);
}

describe('SEC-01 per-IP daily cap', function (): void {
    it('throttles the 16th start of one IP within 24 hours, even when spread over hours (SEC-01)', function (): void {
        Carbon::setTestNow('2026-11-20 00:00:00');

        foreach (range(1, 15) as $i) {
            // At most 4 per hour: the hourly limit of 10 never speaks, only the daily one.
            Carbon::setTestNow(Carbon::parse('2026-11-20 00:00:00')->addHours(intdiv($i - 1, 4))->addMinutes(($i - 1) % 4));
            $this->pv->start(abusePhone($i), $this->purpose, phoneSession(), '192.0.2.50');
        }

        Carbon::setTestNow(Carbon::parse('2026-11-20 05:00:00'));
        $e = null;

        try {
            $this->pv->start(abusePhone(16), $this->purpose, phoneSession(), '192.0.2.50');
        } catch (PhoneVerificationException $e) {
        }

        expect($e)->not->toBeNull()
            ->and($e->reason)->toBe(PhoneVerificationError::Throttled)
            ->and($e->retryAfter)->toBeGreaterThan(3600)
            ->and(count($this->gateway->calls))->toBe(15);

        // Another IP is unaffected; the IPv4-mapped form is the same client.
        $this->pv->start(abusePhone(16), $this->purpose, phoneSession(), '192.0.2.51');
        expectAbuseError(fn () => $this->pv->start(abusePhone(17), $this->purpose, phoneSession(), '::ffff:192.0.2.50'), PhoneVerificationError::Throttled);

        // After 24 hours the bucket is free again.
        Carbon::setTestNow(Carbon::parse('2026-11-21 00:01:00'));
        $this->pv->start(abusePhone(17), $this->purpose, phoneSession(), '192.0.2.50');
    });

    it('counts an IPv6 client by its /64 network (SEC-01)', function (): void {
        Carbon::setTestNow('2026-11-20 00:00:00');

        foreach (range(1, 15) as $i) {
            Carbon::setTestNow(Carbon::parse('2026-11-20 00:00:00')->addHours(intdiv($i - 1, 4))->addMinutes(($i - 1) % 4));
            $this->pv->start(abusePhone($i), $this->purpose, phoneSession(), '2001:db8:7:7::'.dechex($i));
        }

        expectAbuseError(fn () => $this->pv->start(abusePhone(16), $this->purpose, phoneSession(), '2001:db8:7:7:ffff::1'), PhoneVerificationError::Throttled);
        $this->pv->start(abusePhone(16), $this->purpose, phoneSession(), '2001:db8:7:8::1');
    });
});

describe('SEC-01 guest share of the daily budget', function (): void {
    beforeEach(function (): void {
        // 10 a day: guests may use 8, the last 2 stay for customers and known buyers.
        enablePhoneVerification(['daily_limit' => 10]);
        PhoneVerification::factory()->count(8)->create();
    });

    it('refuses a guest with the daily-limit outcome once guests used their 80 %', function (): void {
        expectAbuseError(fn () => $this->pv->start(abusePhone(1), $this->purpose, phoneSession(), '192.0.2.60'), PhoneVerificationError::DailyLimit);

        expect($this->gateway->calls)->toBe([])
            ->and(PhoneVerification::query()->where('outcome', 'blocked')->where('note', 'daily_limit')->count())->toBe(1);
    });

    it('still serves a signed-in customer from the reserved part', function (): void {
        $customer = Customer::factory()->create();

        $this->pv->start(abusePhone(2), $this->purpose, phoneSession(), '192.0.2.61', $customer);

        expect(count($this->gateway->calls))->toBe(1);
    });

    it('still serves a number that has a completed order, but not one with a cancelled or open order', function (): void {
        Order::factory()->create(['customer_phone' => abusePhone(3), 'status' => OrderStatus::Completed]);
        Order::factory()->create(['customer_phone' => abusePhone(4), 'status' => OrderStatus::Cancelled]);
        Order::factory()->create(['customer_phone' => abusePhone(5), 'status' => OrderStatus::New]);

        $this->pv->start(abusePhone(3), $this->purpose, phoneSession(), '192.0.2.62');
        expectAbuseError(fn () => $this->pv->start(abusePhone(4), $this->purpose, phoneSession(), '192.0.2.63'), PhoneVerificationError::DailyLimit);
        expectAbuseError(fn () => $this->pv->start(abusePhone(5), $this->purpose, phoneSession(), '192.0.2.64'), PhoneVerificationError::DailyLimit);
    });

    it('stops everybody at the whole daily limit', function (): void {
        PhoneVerification::factory()->count(2)->create();

        expectAbuseError(fn () => $this->pv->start(abusePhone(6), $this->purpose, phoneSession(), '192.0.2.65', Customer::factory()->create()), PhoneVerificationError::DailyLimit);
    });

    it('keeps the admin test button on the whole budget', function (): void {
        $this->pv->sendTest(User::factory()->create(), abusePhone(7), phoneSession(), '192.0.2.66');

        expect(count($this->gateway->calls))->toBe(1);
    });
});

describe('SEC-01 Telegram-verified sessions', function (): void {
    it('need no start, so they use neither the daily budget nor the per-IP caps', function (): void {
        $account = TelegramAccount::factory()->create(['phone' => abusePhone(8), 'phone_verified_at' => now()]);
        $session = phoneSession();
        $session->put(TelegramMode::SESSION_KEY, true);
        $session->put(TelegramMode::ACCOUNT_KEY, $account->id);

        expect(app(PhoneVerificationGate::class)->isPhoneVerified(abusePhone(8), $this->purpose, null, $session))->toBeTrue()
            ->and($this->gateway->calls)->toBe([])
            ->and($this->pv->dailyUsage())->toBe(0)
            ->and(RateLimiter::attempts('phone-verification:ip-day:'.ClientIpKey::of('192.0.2.70')))->toBe(0)
            ->and(PhoneVerification::query()->count())->toBe(0);
    });
});

describe('SEC-03 count first, then decide', function (): void {
    it('counts the start before judging it and keeps the number and the shop budget untouched on a refusal', function (): void {
        $ipKey = 'phone-verification:ip:'.ClientIpKey::of('192.0.2.80');

        foreach (range(1, 10) as $i) {
            RateLimiter::hit($ipKey, 3600);
        }

        expectAbuseError(fn () => $this->pv->start(abusePhone(9), $this->purpose, phoneSession(), '192.0.2.80'), PhoneVerificationError::Throttled);

        // The 11th hit was counted (a check-then-count version would stay at 10) ...
        expect(RateLimiter::attempts($ipKey))->toBe(11)
            // ... the requester's refusal does not touch the number's counters (a victim's number cannot be burnt) ...
            ->and(RateLimiter::attempts('phone-verification:pause:'.sha1(abusePhone(9))))->toBe(0)
            ->and(RateLimiter::attempts('phone-verification:hour:'.sha1(abusePhone(9))))->toBe(0)
            // ... and the shop-wide budget (accepted rows) is not spent.
            ->and($this->pv->dailyUsage())->toBe(0)
            ->and($this->gateway->calls)->toBe([]);
    });

    it('lets a refused repeat inside the pause count only the pause', function (): void {
        $this->pv->start($phone = abusePhone(10), $this->purpose, $session = phoneSession(), '192.0.2.81');
        expectAbuseError(fn () => $this->pv->start($phone, $this->purpose, $session, '192.0.2.81'), PhoneVerificationError::Throttled);

        expect(RateLimiter::attempts('phone-verification:hour:'.sha1($phone)))->toBe(1)
            ->and(RateLimiter::attempts('phone-verification:pause:'.sha1($phone)))->toBe(2);
    });
});

describe('SEC-04 wrong entries (phone)', function (): void {
    it('does not count entries without an open challenge: the number is never locked by an outsider', function (): void {
        foreach (range(1, 25) as $i) {
            Carbon::setTestNow(now()->addHours($i));
            expectAbuseError(fn () => $this->pv->verify(abusePhone(11), $this->purpose, '1234', phoneSession(), '198.51.100.'.$i), PhoneVerificationError::NoActiveCode);
        }

        Carbon::setTestNow(now()->addMinute());
        // Same hour for all entries of one IP so the lock would trigger: 25 entries from ONE IP within the hourly cap.
        foreach (range(1, 20) as $i) {
            expectAbuseError(fn () => $this->pv->verify(abusePhone(11), $this->purpose, '1234', phoneSession(), '198.51.100.250'), PhoneVerificationError::NoActiveCode);
        }

        $owner = phoneSession();
        $this->pv->start(abusePhone(11), $this->purpose, $owner, '203.0.113.9');
        $this->pv->verify(abusePhone(11), $this->purpose, $this->gateway->lastCode, $owner, '203.0.113.9');

        expect(RateLimiter::attempts('phone-verification:wrong:'.sha1(abusePhone(11)).':'.sha1('198.51.100.250')))->toBe(0);
    });

    it('answers a challenge only from the session that started it, and counts nothing for another session', function (): void {
        $owner = phoneSession();
        $this->pv->start(abusePhone(12), $this->purpose, $owner, '203.0.113.10');
        $code = $this->gateway->lastCode;

        // Even the right code from another session finds no open challenge.
        expectAbuseError(fn () => $this->pv->verify(abusePhone(12), $this->purpose, $code, phoneSession(), '198.51.100.30'), PhoneVerificationError::NoActiveCode);
        expect(RateLimiter::attempts('phone-verification:wrong:'.sha1(abusePhone(12)).':'.sha1('198.51.100.30')))->toBe(0)
            ->and(PhoneVerification::query()->firstOrFail()->attempts)->toBe(0);

        expect($this->pv->verify(abusePhone(12), $this->purpose, $code, $owner, '203.0.113.10')->phone)->toBe(abusePhone(12));
    });

    it('keeps the worst-case guess chance per number and day below 1 % (D-200)', function (): void {
        $chance = PhoneVerificationService::MAX_ATTEMPTS * PhoneVerificationService::PER_NUMBER_PER_DAY / 10 ** PhoneVerificationService::CODE_LENGTH;

        expect($chance)->toBeLessThan(0.01);
    });
});

describe('PV-03 per-number cap of real guesses (D-200)', function (): void {
    function wrongCodeFor(string $code): string
    {
        return $code === '0000' ? '1111' : '0000';
    }

    it('refuses the 16th real wrong guess at a number across all requesters, but not starts or the owner entries that were no guesses', function (): void {
        Carbon::setTestNow('2026-11-22 10:00:00');
        $phone = abusePhone(21);

        // Entries without an open challenge are no guesses: they never use the cap.
        foreach (range(1, 12) as $i) {
            expectAbuseError(fn () => $this->pv->verify($phone, $this->purpose, '1234', phoneSession(), '198.51.100.'.$i), PhoneVerificationError::NoActiveCode);
        }

        expect(RateLimiter::attempts('phone-verification:guesses:'.sha1($phone)))->toBe(0);

        // Three challenges of three outsiders (their own IPs, so no per-requester lock): 3 x 5 = 15 real guesses.
        foreach (range(1, 3) as $k) {
            Carbon::setTestNow(now()->addSeconds(61));
            $session = phoneSession();
            $this->pv->start($phone, $this->purpose, $session, '203.0.113.'.$k);

            foreach (range(1, 5) as $n) {
                $code = wrongCodeFor((string) $this->gateway->lastCode);
                $expected = $n === 5 ? PhoneVerificationError::TooManyAttempts : PhoneVerificationError::WrongCode;
                expectAbuseError(fn () => $this->pv->verify($phone, $this->purpose, $code, $session, '203.0.113.'.$k), $expected);
            }
        }

        expect(RateLimiter::attempts('phone-verification:guesses:'.sha1($phone)))->toBe(15);

        // A 4th start is refused with the same outcome as the entry, and the provider is not called (no paid call/SMS).
        Carbon::setTestNow(now()->addHour());
        $callsBefore = count($this->gateway->calls);
        $rowsBefore = PhoneVerification::query()->count();
        expectAbuseError(fn () => $this->pv->start($phone, $this->purpose, phoneSession(), '203.0.113.99'), PhoneVerificationError::NumberLocked);
        expect(count($this->gateway->calls))->toBe($callsBefore)
            ->and(PhoneVerification::query()->count())->toBe($rowsBefore);
    });

    it('does not use the cap for a correct entry or an exhausted code', function (): void {
        $phone = abusePhone(22);
        $owner = phoneSession();
        $this->pv->start($phone, $this->purpose, $owner, '203.0.113.5');
        $this->pv->verify($phone, $this->purpose, (string) $this->gateway->lastCode, $owner, '203.0.113.5');

        expect(RateLimiter::attempts('phone-verification:guesses:'.sha1($phone)))->toBe(0);
    });
});

describe('D-198 guest share alert', function (): void {
    it('sends the lighter guest-share alert, not the daily-limit one, when only the guest share is used up', function (): void {
        enablePhoneVerification(['daily_limit' => 10]);
        PhoneVerification::factory()->count(8)->create();
        Cache::forget('phone-verification:alert:daily_limit');
        Cache::forget('phone-verification:alert:guest_share');

        expectAbuseError(fn () => $this->pv->start(abusePhone(31), $this->purpose, phoneSession(), '192.0.2.70'), PhoneVerificationError::DailyLimit);

        expect(Cache::has('phone-verification:alert:guest_share'))->toBeTrue()
            ->and(Cache::has('phone-verification:alert:daily_limit'))->toBeFalse();

        PhoneVerification::factory()->count(2)->create();
        Carbon::setTestNow(now()->addMinutes(5));
        expectAbuseError(fn () => $this->pv->start(abusePhone(32), $this->purpose, phoneSession(), '192.0.2.71'), PhoneVerificationError::DailyLimit);

        expect(Cache::has('phone-verification:alert:daily_limit'))->toBeTrue();
    });
});

describe('SEC-03 atomic counters (D-199)', function (): void {
    it('fails closed when the lock of a counter cannot be taken (entry limit per IP)', function (): void {
        $phone = abusePhone(41);
        $owner = phoneSession();
        $this->pv->start($phone, $this->purpose, $owner, '203.0.113.7');

        $lock = Cache::lock('rate-limit:'.sha1('phone-verification:entry-ip:203.0.113.7'), 30);
        expect($lock->get())->toBeTrue();

        // Even the right code is refused while the counter cannot be taken; and the entry is not judged at all.
        expectAbuseError(fn () => $this->pv->verify($phone, $this->purpose, (string) $this->gateway->lastCode, $owner, '203.0.113.7'), PhoneVerificationError::Throttled);
        expect(PhoneVerification::query()->firstOrFail()->attempts)->toBe(0);

        $lock->release();
    });
});
