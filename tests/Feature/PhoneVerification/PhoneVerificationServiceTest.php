<?php

declare(strict_types=1);

use App\Enums\PhoneProviderFailureKind;
use App\Enums\PhoneVerificationError;
use App\Enums\PhoneVerificationMethod;
use App\Enums\PhoneVerificationOutcome;
use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\PhoneVerificationException;
use App\Jobs\SendPhoneVerificationAlert;
use App\Models\Customer;
use App\Models\PhoneVerification;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Settings\NotificationSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

const PV_PHONE = '+79991234567';
const PV_IP = '192.0.2.10';

beforeEach(function (): void {
    $this->settings = enablePhoneVerification();
    $this->gateway = phoneStub();
    $this->session = phoneSession();
    $this->pv = app(PhoneVerificationService::class);
    $this->purpose = PhoneVerificationPurpose::GuestCheckout;
});

afterEach(fn () => Carbon::setTestNow());

/** Expects the callback to throw a PhoneVerificationException with the reason. */
function expectPvError(Closure $call, PhoneVerificationError $reason): PhoneVerificationException
{
    try {
        $call();
    } catch (PhoneVerificationException $e) {
        expect($e->reason)->toBe($reason);

        return $e;
    }

    throw new RuntimeException('Expected PhoneVerificationException '.$reason->value);
}

describe('flash call (PV-02, PV-03)', function (): void {
    it('passes a 4-digit client code to a driver that takes it and stores only the HMAC', function (): void {
        $start = $this->pv->start('8 (999) 123-45-67', $this->purpose, $this->session, PV_IP);

        expect($start->skipped)->toBeFalse()
            ->and($start->phone)->toBe(PV_PHONE)
            ->and($start->method)->toBe(PhoneVerificationMethod::FlashCall)
            ->and($this->gateway->types())->toBe(['flash'])
            ->and($this->gateway->calls[0]['phone'])->toBe(PV_PHONE)
            ->and($this->gateway->calls[0]['arg'])->toMatch('/^\d{4}$/');

        $code = $this->gateway->lastCode;
        $row = PhoneVerification::query()->firstOrFail();

        expect($row->outcome)->toBe(PhoneVerificationOutcome::Pending)
            ->and($row->phone)->toBe(PV_PHONE)
            ->and($row->attempts)->toBe(0)
            ->and($row->code_hash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($row->session_hash)->toMatch('/^[0-9a-f]{64}$/')
            ->and($row->provider_request_id)->toBe('req-1')
            ->and($row->cost)->toBe('1.3000')
            ->and($row->ip)->toBe(PV_IP)
            ->and((int) $row->created_at->diffInMinutes($row->expires_at))->toBe(5)
            ->and($start->canRequestSms)->toBeFalse();

        expect(DB::table('phone_verifications')->get()->map(fn ($r): string => json_encode($r))->implode(''))->not->toContain('"'.$code.'"');
    });

    it('takes the code from the answer of a driver that generates it', function (): void {
        $gateway = phoneStub(callerCode: false);

        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        expect($gateway->calls[0]['arg'])->toBeNull();

        $this->pv->verify(PV_PHONE, $this->purpose, $gateway->providerCode, $this->session, PV_IP);

        expect(PhoneVerification::query()->firstOrFail()->outcome)->toBe(PhoneVerificationOutcome::Verified);
    });

    it('verifies the right code once and remembers the number in the session for the same purpose for 24 hours', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        $row = $this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP);

        expect($row->outcome)->toBe(PhoneVerificationOutcome::Verified)
            ->and($row->verified_at)->not->toBeNull()
            ->and($this->pv->isVerified('8 999 123 45 67', $this->purpose, $this->session))->toBeTrue()
            ->and($this->pv->isVerified(PV_PHONE, PhoneVerificationPurpose::Wholesale, $this->session))->toBeFalse()
            ->and($this->pv->isVerified('+79990000000', $this->purpose, $this->session))->toBeFalse()
            ->and($this->pv->isVerified(PV_PHONE, $this->purpose, phoneSession()))->toBeFalse();

        // Single use.
        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP), PhoneVerificationError::NoActiveCode);

        Carbon::setTestNow(now()->addHours(23));
        expect($this->pv->isVerified(PV_PHONE, $this->purpose, $this->session))->toBeTrue();

        Carbon::setTestNow(now()->addHours(2));
        expect($this->pv->isVerified(PV_PHONE, $this->purpose, $this->session))->toBeFalse();
    });

    it('forgets the verified number of a purpose', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        $this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP);

        $this->pv->forget($this->purpose, $this->session);

        expect($this->pv->isVerified(PV_PHONE, $this->purpose, $this->session))->toBeFalse();
    });

    it('rejects a wrong code and, after 5 attempts, even the right one', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        $right = $this->gateway->lastCode;
        $wrong = $right === '0000' ? '1111' : '0000';

        foreach (range(1, 4) as $i) {
            expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $wrong, $this->session, PV_IP), PhoneVerificationError::WrongCode);
        }

        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $wrong, $this->session, PV_IP), PhoneVerificationError::TooManyAttempts);
        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $right, $this->session, PV_IP), PhoneVerificationError::NoActiveCode);

        $row = PhoneVerification::query()->firstOrFail();
        expect($row->outcome)->toBe(PhoneVerificationOutcome::Failed)->and($row->attempts)->toBe(5);
    });

    it('rejects a code after its time to live', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        Carbon::setTestNow(now()->addMinutes(5)->addSecond());

        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP), PhoneVerificationError::Expired);
        expect(PhoneVerification::query()->firstOrFail()->outcome)->toBe(PhoneVerificationOutcome::Expired);
    });

    it('honours a different time to live from the settings', function (): void {
        enablePhoneVerification(['code_ttl_minutes' => 2]);
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        expect((int) PhoneVerification::query()->firstOrFail()->created_at->diffInMinutes(PhoneVerification::query()->firstOrFail()->expires_at))->toBe(2);
    });

    it('accepts a code only from the session that asked for it (D-108)', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        $other = phoneSession();
        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $other, PV_IP), PhoneVerificationError::NoActiveCode);

        // The code of another purpose does not fit either.
        expectPvError(fn () => $this->pv->verify(PV_PHONE, PhoneVerificationPurpose::Wholesale, $this->gateway->lastCode, $this->session, PV_IP), PhoneVerificationError::NoActiveCode);

        expect($this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP)->outcome)->toBe(PhoneVerificationOutcome::Verified);
    });

    it('replaces the open code of the same number, purpose and session by the new one', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        $first = $this->gateway->lastCode;
        Carbon::setTestNow(now()->addSeconds(61));
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        expect(PhoneVerification::query()->where('outcome', 'pending')->count())->toBe(1)
            ->and(PhoneVerification::query()->where('outcome', 'expired')->count())->toBe(1);

        if ($first !== $this->gateway->lastCode) {
            expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $first, $this->session, PV_IP), PhoneVerificationError::WrongCode);
        }
    });

    it('records the customer', function (): void {
        $customer = Customer::factory()->create();

        $this->pv->start(PV_PHONE, PhoneVerificationPurpose::Registration, $this->session, PV_IP, $customer);

        expect(PhoneVerification::query()->firstOrFail()->customer_id)->toBe($customer->id);
    });
});

describe('SMS (PV-04)', function (): void {
    it('sends the SMS first and only in the SMS-only mode', function (): void {
        enablePhoneVerification(['mode' => 'sms']);

        $start = $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        expect($start->method)->toBe(PhoneVerificationMethod::Sms)
            ->and($this->gateway->types())->toBe(['sms'])
            ->and($this->gateway->calls[0]['arg'])->toBe('Код подтверждения: '.$this->gateway->lastCode.'. '.config('app.name'))
            ->and(mb_strlen($this->gateway->calls[0]['arg']))->toBeLessThanOrEqual(70)
            ->and($start->canRequestSms)->toBeFalse();

        expectPvError(fn () => $this->pv->requestSms(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::SmsNotAvailable);
        expect($this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP)->method)->toBe(PhoneVerificationMethod::Sms);
    });

    it('sends the SMS in the call-then-SMS mode only by the separate action, not earlier than 60 seconds after the call', function (): void {
        enablePhoneVerification(['mode' => 'flash_call_then_sms']);

        $start = $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        // The first request is a call and nothing else.
        expect($this->gateway->types())->toBe(['flash'])->and($start->canRequestSms)->toBeTrue();

        Carbon::setTestNow(now()->addSeconds(30));
        $e = expectPvError(fn () => $this->pv->requestSms(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::SmsTooEarly);
        expect($e->retryAfter)->toBeBetween(29, 31)->and($e->isThrottle())->toBeTrue()->and($this->gateway->types())->toBe(['flash']);

        Carbon::setTestNow(now()->addSeconds(31));
        $sms = $this->pv->requestSms(PV_PHONE, $this->purpose, $this->session, PV_IP);

        expect($sms->method)->toBe(PhoneVerificationMethod::Sms)->and($this->gateway->types())->toBe(['flash', 'sms']);

        // One SMS per call, and the SMS code is the valid one now.
        expectPvError(fn () => $this->pv->requestSms(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::SmsNotAvailable);
        expect($this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP)->method)->toBe(PhoneVerificationMethod::Sms);
    });

    it('refuses the SMS action in the call-only mode, without a call and from another session', function (): void {
        expectPvError(fn () => $this->pv->requestSms(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::SmsNotAvailable);

        enablePhoneVerification(['mode' => 'flash_call_then_sms']);
        expectPvError(fn () => $this->pv->requestSms(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::SmsNotAvailable);

        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        Carbon::setTestNow(now()->addSeconds(70));

        expectPvError(fn () => $this->pv->requestSms(PV_PHONE, $this->purpose, phoneSession(), PV_IP), PhoneVerificationError::SmsNotAvailable);
        expect($this->gateway->types())->toBe(['flash']);
    });
});

describe('numbers and prefixes (PV-06)', function (): void {
    it('refuses numbers that are not valid or not in the allowed prefixes', function (): void {
        expectPvError(fn () => $this->pv->start('12345', $this->purpose, $this->session, PV_IP), PhoneVerificationError::InvalidPhone);
        // A Russian landline.
        expectPvError(fn () => $this->pv->start('+7 495 123-45-67', $this->purpose, $this->session, PV_IP), PhoneVerificationError::PrefixNotAllowed);
        // Kazakhstan is not allowed by default.
        expectPvError(fn () => $this->pv->start('+7 701 123-45-67', $this->purpose, $this->session, PV_IP), PhoneVerificationError::PrefixNotAllowed);

        expect($this->gateway->calls)->toBe([]);
    });

    it('allows another prefix from the settings', function (): void {
        enablePhoneVerification(['allowed_prefixes' => ['+79', '+77']]);

        $start = $this->pv->start('+7 701 123-45-67', $this->purpose, $this->session, PV_IP);

        expect($start->phone)->toBe('+77011234567');
    });

    it('is unavailable when switched off', function (): void {
        enablePhoneVerification(['enabled' => false]);

        expect($this->pv->isEnabled())->toBeFalse();
        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::Disabled);
    });
});

describe('limits (PV-06, NF-53)', function (): void {
    it('allows one request per 60 seconds per number', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        $e = expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::Throttled);
        expect($e->retryAfter)->toBeBetween(1, 60)->and($e->isThrottle())->toBeTrue()->and(count($this->gateway->calls))->toBe(1);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        expect(count($this->gateway->calls))->toBe(2);
    });

    it('allows 3 requests per hour per number', function (): void {
        foreach (range(1, 3) as $i) {
            $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
            Carbon::setTestNow(now()->addSeconds(61));
        }

        $e = expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::Throttled);
        expect($e->retryAfter)->toBeGreaterThan(60)->and(count($this->gateway->calls))->toBe(3);

        Carbon::setTestNow(now()->addHour());
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        expect(count($this->gateway->calls))->toBe(4);
    });

    it('allows 6 requests per day per number', function (): void {
        $start = now();

        foreach ([0, 1, 2] as $hour) {
            foreach (range(0, 1) as $i) {
                // Fresh IP and session each time: only the limits of the number are under test.
                $this->pv->start(PV_PHONE, $this->purpose, phoneSession(), '198.51.100.'.($hour * 2 + $i + 1));
                Carbon::setTestNow(now()->addSeconds(61));
            }

            Carbon::setTestNow($start->copy()->addHours($hour + 1)->addMinutes(2));
        }

        expect(count($this->gateway->calls))->toBe(6);

        $e = expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, phoneSession(), '198.51.100.99'), PhoneVerificationError::Throttled);
        expect($e->retryAfter)->toBeGreaterThan(3600)->and(count($this->gateway->calls))->toBe(6);
    });

    it('allows 10 requests per hour per IP', function (): void {
        foreach (range(1, 10) as $i) {
            $this->pv->start('+7999000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $this->purpose, phoneSession(), PV_IP);
        }

        expectPvError(fn () => $this->pv->start('+79990009999', $this->purpose, phoneSession(), PV_IP), PhoneVerificationError::Throttled);
        // Another address is fine.
        $this->pv->start('+79990009999', $this->purpose, phoneSession(), '192.0.2.77');

        expect(count($this->gateway->calls))->toBe(11);
    });

    it('counts an IPv6 address by its /64 subnet', function (): void {
        foreach (range(1, 10) as $i) {
            $this->pv->start('+7999000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $this->purpose, phoneSession(), '2001:db8:1:2::'.dechex($i));
        }

        // Same /64, another interface identifier.
        expectPvError(fn () => $this->pv->start('+79990009999', $this->purpose, phoneSession(), '2001:db8:1:2:aaaa:bbbb:cccc:dddd'), PhoneVerificationError::Throttled);
        // The next /64 is another subnet.
        $this->pv->start('+79990009999', $this->purpose, phoneSession(), '2001:db8:1:3::1');

        expect(count($this->gateway->calls))->toBe(11);
    });

    it('allows 10 requests per hour per session', function (): void {
        foreach (range(1, 10) as $i) {
            $this->pv->start('+7999000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $this->purpose, $this->session, '198.51.100.'.$i);
        }

        expectPvError(fn () => $this->pv->start('+79990009999', $this->purpose, $this->session, '198.51.100.200'), PhoneVerificationError::Throttled);
        $this->pv->start('+79990009999', $this->purpose, phoneSession(), '198.51.100.200');

        expect(count($this->gateway->calls))->toBe(11);
    });

    it('limits 20 code entries per hour per IP', function (): void {
        foreach (range(1, 20) as $i) {
            expectPvError(fn () => $this->pv->verify('+7999000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), $this->purpose, '1234', $this->session, PV_IP), PhoneVerificationError::NoActiveCode);
        }

        expectPvError(fn () => $this->pv->verify('+79990009999', $this->purpose, '1234', $this->session, PV_IP), PhoneVerificationError::Throttled);
    });

    it('locks a number for 24 hours after 15 wrong entries a day', function (): void {
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        $right = $this->gateway->lastCode;
        $wrong = $right === '0000' ? '1111' : '0000';

        foreach (range(1, 15) as $i) {
            expect(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $wrong, $this->session, '192.0.2.'.$i))->toThrow(PhoneVerificationException::class);
        }

        // Even the right code and a new request are refused now.
        $e = expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $right, $this->session, PV_IP), PhoneVerificationError::NumberLocked);
        expect($e->retryAfter)->toBeBetween(86000, 86400);
        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, phoneSession(), '203.0.113.5'), PhoneVerificationError::NumberLocked);
        expect(count($this->gateway->calls))->toBe(1);

        Carbon::setTestNow(now()->addHours(24)->addMinute());
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        expect(count($this->gateway->calls))->toBe(2);
    });
});

describe('shop daily limit (PV-06)', function (): void {
    it('counts today\'s rows accepted by the provider in the database and blocks beyond the limit', function (): void {
        enablePhoneVerification(['daily_limit' => 2]);
        PhoneVerification::factory()->count(2)->create();

        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::DailyLimit);

        expect($this->gateway->calls)->toBe([]);
        $blocked = PhoneVerification::query()->where('outcome', 'blocked')->firstOrFail();
        expect($blocked->note)->toBe('daily_limit')->and($blocked->provider_request_id)->toBeNull();
        // The blocked row itself is not an accepted request.
        expect($this->pv->dailyUsage())->toBe(2);
    });

    it('does not count skipped rows, blocked rows or rows of yesterday', function (): void {
        enablePhoneVerification(['daily_limit' => 2]);
        PhoneVerification::factory()->count(5)->skipped()->create();
        PhoneVerification::factory()->count(5)->create(['created_at' => now()->subDay()->startOfDay()->subMinute()]);

        expect($this->pv->dailyUsage())->toBe(0);
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        expect($this->pv->dailyUsage())->toBe(1);
    });

    it('blocks even when the failure setting is «skip»', function (): void {
        enablePhoneVerification(['daily_limit' => 0, 'on_provider_failure' => 'skip']);

        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::DailyLimit);
    });

    it('may skip when the owner chose so for the daily limit, and the skip does not use the limit', function (): void {
        enablePhoneVerification(['daily_limit' => 0, 'on_daily_limit' => 'skip']);

        $start = $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        expect($start->skipped)->toBeTrue()
            ->and($start->verification->outcome)->toBe(PhoneVerificationOutcome::SkippedProvider)
            ->and($start->verification->note)->toBe('daily_limit')
            ->and($this->gateway->calls)->toBe([])
            ->and($this->pv->dailyUsage())->toBe(0);
    });
});

describe('provider failures (PV-05)', function (): void {
    dataset('failure kinds', [
        'network' => [PhoneProviderFailureKind::Network],
        'timeout' => [PhoneProviderFailureKind::Timeout],
        'server 5xx' => [PhoneProviderFailureKind::Server],
        'authorization' => [PhoneProviderFailureKind::Auth],
    ]);

    it('skips the verification on «skip» and does not use up the daily limit', function (PhoneProviderFailureKind $kind): void {
        Queue::fake();
        $this->gateway->failWith($kind);

        $start = $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        expect($start->skipped)->toBeTrue()
            ->and($start->verification->outcome)->toBe(PhoneVerificationOutcome::SkippedProvider)
            ->and($start->verification->note)->toBe($kind->value)
            ->and($start->verification->provider_request_id)->toBeNull()
            ->and($this->pv->dailyUsage())->toBe(0)
            ->and(count($this->gateway->calls))->toBe(1)
            ->and($this->pv->isVerified(PV_PHONE, $this->purpose, $this->session))->toBeFalse();
    })->with('failure kinds');

    it('blocks on «block»', function (PhoneProviderFailureKind $kind): void {
        enablePhoneVerification(['on_provider_failure' => 'block']);
        Queue::fake();
        $this->gateway->failWith($kind);

        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::ProviderUnavailable);

        $row = PhoneVerification::query()->firstOrFail();
        expect($row->outcome)->toBe(PhoneVerificationOutcome::Blocked)->and($row->note)->toBe($kind->value);
    })->with('failure kinds');

    it('tells the shop about a failure at most once an hour (e-mail and work chat)', function (): void {
        Queue::fake();
        $notifications = app(NotificationSettings::class);
        $notifications->notify_emails = ['owner@example.com'];
        $notifications->notify_shop_email = true;
        $notifications->notify_shop_telegram = false;
        $notifications->save();
        $this->gateway->failWith(PhoneProviderFailureKind::Server);

        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        Queue::assertPushed(SendPhoneVerificationAlert::class, 1);
        Queue::assertPushed(SendPhoneVerificationAlert::class, fn (SendPhoneVerificationAlert $job): bool => $job->topic === 'provider_failure' && $job->to === 'owner@example.com' && $job->params === ['kind' => 'server']);

        Carbon::setTestNow(now()->addHour());
        $this->pv->start('+79990000001', $this->purpose, $this->session, PV_IP);
        Queue::assertPushed(SendPhoneVerificationAlert::class, 2);
    });

    it('treats an empty balance as no failure: it always blocks', function (): void {
        Queue::fake();
        enablePhoneVerification(['on_provider_failure' => 'skip']);
        $this->gateway->refuseWithBalance();

        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::ProviderRefused);

        $row = PhoneVerification::query()->firstOrFail();
        expect($row->outcome)->toBe(PhoneVerificationOutcome::Blocked)->and($row->note)->toBe('balance');
    });

    it('never skips limits, wrong codes, expired codes or exceeded attempts', function (): void {
        enablePhoneVerification(['on_provider_failure' => 'skip', 'on_daily_limit' => 'skip']);

        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        // Limit.
        expectPvError(fn () => $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP), PhoneVerificationError::Throttled);
        // Wrong code.
        $wrong = $this->gateway->lastCode === '0000' ? '1111' : '0000';
        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $wrong, $this->session, PV_IP), PhoneVerificationError::WrongCode);
        // Expired code.
        Carbon::setTestNow(now()->addMinutes(6));
        expectPvError(fn () => $this->pv->verify(PV_PHONE, $this->purpose, $this->gateway->lastCode, $this->session, PV_IP), PhoneVerificationError::Expired);

        expect($this->pv->isVerified(PV_PHONE, $this->purpose, $this->session))->toBeFalse();
    });

    it('does not skip anything for the test button', function (): void {
        Queue::fake();
        $this->gateway->failWith(PhoneProviderFailureKind::Network);

        expectPvError(fn () => $this->pv->start(PV_PHONE, PhoneVerificationPurpose::Test, $this->session, PV_IP), PhoneVerificationError::ProviderUnavailable);
    });

    it('logs the number masked and never the code', function (): void {
        Queue::fake();
        Log::spy();
        $this->gateway->failWith(PhoneProviderFailureKind::Timeout);

        $this->pv->start(PV_PHONE, $this->purpose, $this->session, PV_IP);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => ($context['phone'] ?? null) === '+7******4567'
            && ! str_contains(json_encode($context), '9991234567'))->once();
    });
});

describe('housekeeping (PV-08)', function (): void {
    it('expires stale pending rows and deletes rows older than 90 days', function (): void {
        $old = PhoneVerification::factory()->create(['created_at' => now()->subDays(91)]);
        $fresh = PhoneVerification::factory()->create(['created_at' => now()->subDays(89)]);
        $stale = PhoneVerification::factory()->create(['expires_at' => now()->subMinute()]);

        $deleted = $this->pv->purgeOlderThan();

        expect($deleted)->toBe(1)
            ->and(PhoneVerification::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and(PhoneVerification::query()->whereKey($fresh->id)->exists())->toBeTrue()
            ->and($stale->refresh()->outcome)->toBe(PhoneVerificationOutcome::Expired);
    });
});
