<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\CustomerThrottledException;
use App\Mail\CustomerCodeGuessingMail;
use App\Mail\CustomerCodeMail;
use App\Models\Customer;
use App\Models\CustomerVerificationCode;
use App\Settings\CustomerSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-time e-mail codes (CA-04, CA-12, TZ 17.6, D-072).
 *
 *  - The code is 6 digits from `random_int`. Only an HMAC-SHA256 (app key, over id|purpose|email|code) is stored, so a
 *    code cannot be read back from the database.
 *  - A code lives `code_ttl_minutes`, takes `code_max_attempts` wrong entries (then it is annulled), is single use,
 *    and a new code annuls the previous open codes of the same address and purpose.
 *  - Limits (RateLimiter on the cache): 5 code requests per hour per e-mail and 10 per IP, 20 entries per hour per IP,
 *    a resend pause per address and purpose, and 20 wrong entries per day per e-mail, after which every code request
 *    and entry for that address is blocked for 24 hours and the owner is told by mail.
 *
 * Every limit is evaluated the same way for addresses that have no account, so answers do not reveal whether an
 * account exists (CA-05): {@see request()} with `$deliver = false` runs all checks and then sends nothing.
 *
 * Codes are never logged. Callers pass the e-mail already trimmed and lower-cased.
 */
final class VerificationCodeService
{
    public const REQUESTS_PER_EMAIL_PER_HOUR = 5;

    public const REQUESTS_PER_IP_PER_HOUR = 10;

    public const ENTRIES_PER_IP_PER_HOUR = 20;

    public const WRONG_ENTRIES_PER_DAY = 20;

    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(private readonly CustomerSettings $settings) {}

    /**
     * Issues a code and queues the mail (CA-04). The previous open codes of this address and purpose are annulled.
     *
     * @param  array<string, mixed>|null  $payload  stored with the code (the new address of a change-email request)
     * @param  bool  $deliver  false when the address has no eligible account: all limits apply, nothing is stored or sent
     *
     * @throws CustomerThrottledException an hourly limit or the 24-hour lock
     * @throws CustomerAuthException the resend pause is not over
     */
    public function request(
        VerificationPurpose $purpose,
        string $email,
        string $ip,
        ?Customer $customer = null,
        ?array $payload = null,
        bool $deliver = true,
    ): void {
        $this->assertNotLocked($email);

        $emailKey = 'customer-code:email:'.$this->hash($email);
        $ipKey = 'customer-code:ip:'.$ip;

        if (RateLimiter::tooManyAttempts($emailKey, self::REQUESTS_PER_EMAIL_PER_HOUR)) {
            throw new CustomerThrottledException(RateLimiter::availableIn($emailKey));
        }

        if (RateLimiter::tooManyAttempts($ipKey, self::REQUESTS_PER_IP_PER_HOUR)) {
            throw new CustomerThrottledException(RateLimiter::availableIn($ipKey));
        }

        $pauseKey = 'customer-code:pause:'.$purpose->value.':'.$this->hash($email);

        if (RateLimiter::tooManyAttempts($pauseKey, 1)) {
            throw new CustomerAuthException(__('account.errors.resend_wait', ['time' => CustomerThrottledException::humanTime(RateLimiter::availableIn($pauseKey))]), 'code');
        }

        RateLimiter::hit($emailKey, self::HOUR);
        RateLimiter::hit($ipKey, self::HOUR);
        RateLimiter::hit($pauseKey, max(1, $this->settings->code_resend_seconds));

        if (! $deliver) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($purpose, $email, $ip, $customer, $payload, $code): void {
            $now = now();

            CustomerVerificationCode::query()
                ->where('email', $email)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            $row = new CustomerVerificationCode;
            $row->forceFill([
                'customer_id' => $customer?->id,
                'email' => $email,
                'purpose' => $purpose,
                'code_hash' => str_repeat('0', 64),
                'attempts' => 0,
                'expires_at' => $now->copy()->addMinutes(max(1, $this->settings->code_ttl_minutes)),
                'sent_at' => $now,
                'consumed_at' => null,
                'ip' => $ip,
                'payload' => $payload,
            ])->save();

            // The id is part of the hashed message, so the hash is written after the insert.
            $row->forceFill(['code_hash' => $this->digest($row->id, $purpose, $email, $code)])->save();
        });

        // The mailable is queued (and encrypted in the payload); a mail failure never reaches the request (CA-25).
        Mail::to($email)->queue(new CustomerCodeMail($purpose, $code, max(1, $this->settings->code_ttl_minutes), $customer?->name));
    }

    /**
     * Checks an entered code and consumes it (single use). The answer for "no such code", "expired", "wrong" and
     * "too many attempts" is one and the same message.
     *
     * @return CustomerVerificationCode the consumed row (`payload`, `customer_id`)
     *
     * @throws CustomerThrottledException the IP limit or the 24-hour lock
     * @throws CustomerAuthException the code is not valid
     */
    public function verify(VerificationPurpose $purpose, string $email, string $input, string $ip): CustomerVerificationCode
    {
        $this->assertNotLocked($email);

        $ipKey = 'customer-code:entry-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($ipKey, self::ENTRIES_PER_IP_PER_HOUR)) {
            throw new CustomerThrottledException(RateLimiter::availableIn($ipKey));
        }

        RateLimiter::hit($ipKey, self::HOUR);

        $input = trim($input);
        $row = CustomerVerificationCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose->value)
            ->open()
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            $this->registerWrongEntry($email);

            throw $this->invalid();
        }

        // Atomic: two parallel requests cannot spend more than the allowed number of attempts.
        $counted = CustomerVerificationCode::query()
            ->whereKey($row->id)
            ->whereNull('consumed_at')
            ->where('attempts', '<', max(1, $this->settings->code_max_attempts))
            ->increment('attempts');

        if ($counted === 0) {
            $this->annul($row);
            $this->registerWrongEntry($email);

            throw $this->invalid();
        }

        $row->attempts++;
        $matches = preg_match('/^\d{6}$/', $input) === 1
            && hash_equals($row->code_hash, $this->digest($row->id, $purpose, $email, $input));

        if (! $matches) {
            if ($row->attempts >= max(1, $this->settings->code_max_attempts)) {
                $this->annul($row);
            }

            $this->registerWrongEntry($email);

            throw $this->invalid();
        }

        // Single use: only the request that flips `consumed_at` wins.
        $consumed = CustomerVerificationCode::query()
            ->whereKey($row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($consumed !== 1) {
            throw $this->invalid();
        }

        $row->setAttribute('consumed_at', now());
        $row->syncOriginal();

        return $row;
    }

    /**
     * Seconds until entry and requests for the address work again; 0 when it is not locked (CA-12).
     */
    public function lockedFor(string $email): int
    {
        $until = Cache::get($this->lockKey($email));

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    /**
     * Deletes codes that expired more than `$keepDays` days ago (for shop:purge-customer-codes).
     */
    public function purgeExpired(int $keepDays = 1): int
    {
        return CustomerVerificationCode::query()->where('expires_at', '<', now()->subDays(max(0, $keepDays)))->delete();
    }

    private function assertNotLocked(string $email): void
    {
        $left = $this->lockedFor($email);

        if ($left > 0) {
            throw new CustomerThrottledException($left);
        }
    }

    private function registerWrongEntry(string $email): void
    {
        $key = 'customer-code:wrong:'.$this->hash($email);
        $count = RateLimiter::hit($key, self::DAY);

        if ($count < self::WRONG_ENTRIES_PER_DAY) {
            return;
        }

        // Engage the lock once; later wrong entries cannot happen while it holds.
        $until = now()->getTimestamp() + self::DAY;

        if (! Cache::add($this->lockKey($email), $until, self::DAY)) {
            return;
        }

        $owner = Customer::query()->where('email', $email)->exists();

        if ($owner) {
            Mail::to($email)->queue(new CustomerCodeGuessingMail);
        }
    }

    /**
     * An annulled code reads as consumed.
     */
    private function annul(CustomerVerificationCode $row): void
    {
        CustomerVerificationCode::query()->whereKey($row->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }

    private function invalid(): CustomerAuthException
    {
        return new CustomerAuthException(__('account.errors.code_invalid'), 'code');
    }

    private function lockKey(string $email): string
    {
        return 'customer-code:lock:'.$this->hash($email);
    }

    private function hash(string $value): string
    {
        return sha1(Str::lower($value));
    }

    /**
     * HMAC-SHA256 with the application key over `id|purpose|email|code` (TZ 17.6).
     */
    private function digest(int $id, VerificationPurpose $purpose, string $email, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$purpose->value.'|'.$email.'|'.$code, app('encrypter')->getKey());
    }
}
