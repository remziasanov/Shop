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
use App\Support\AtomicRateLimiter;
use App\Support\ClientIpKey;
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
 *    and a new code annuls the previous open codes of the same address, purpose and browser session (D-195: a code of
 *    another session is never annulled by a stranger's request).
 *  - Limits (counted first and compared after, atomically through {@see AtomicRateLimiter}, SEC-03): 10 code requests
 *    per hour per IP (counted first), 5 per hour per requester (address + client IP key), 30 per 24 hours per e-mail
 *    across all requesters (SEC-04, RE-01), 20 entries per hour per IP,
 *    a resend pause per address, purpose and client IP key, and 20 wrong entries per day per requester (address + client IP key),
 *    after which code requests and entries of that requester for that address are blocked for 24 hours and the owner
 *    is told by mail.
 *  - A code is verified only from the browser session that requested it (`session_hash`, SEC-04); codes without a
 *    binding (issued by staff) accept any session. An entry that finds no open code of the session counts as a wrong
 *    entry as well (CA-05: otherwise the lock would show whether an account exists).
 *
 * Every limit is evaluated the same way for addresses that have no account, so answers do not reveal whether an
 * account exists (CA-05): {@see request()} with `$deliver = false` runs all checks and then sends nothing.
 *
 * Codes are never logged. Callers pass the e-mail already trimmed and lower-cased.
 */
final class VerificationCodeService
{
    /** RE-01: the narrow limit belongs to the requester (address + client IP key), a stranger cannot use up the owner's quota. */
    public const REQUESTS_PER_EMAIL_PER_HOUR = 5;

    public const REQUESTS_PER_IP_PER_HOUR = 10;

    public const ENTRIES_PER_IP_PER_HOUR = 20;

    public const WRONG_ENTRIES_PER_DAY = 20;

    /** SEC-04, RE-01: the global bound on guessing; all requesters of one address share it (30 x 5 tries / 10^6 = 0.015 % a day). */
    public const REQUESTS_PER_EMAIL_PER_DAY = 30;

    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(private readonly CustomerSettings $settings) {}

    /**
     * Issues a code and queues the mail (CA-04). The previous open codes of this address and purpose are annulled.
     *
     * @param  array<string, mixed>|null  $payload  stored with the code (the new address of a change-email request)
     * @param  bool  $deliver  false when the address has no eligible account: all limits apply, nothing is stored or sent
     * @param  string|null  $binding  token of the requesting browser session; the code can be entered only from it
     * @param  string|null  $boundSessionHash  the same binding as an already stored SHA-256 (staff-issued codes), used when no token is given
     *
     * @throws CustomerThrottledException an hourly or daily limit, or the 24-hour lock of this requester
     * @throws CustomerAuthException the resend pause is not over
     */
    public function request(
        VerificationPurpose $purpose,
        string $email,
        string $ip,
        ?Customer $customer = null,
        ?array $payload = null,
        bool $deliver = true,
        ?string $binding = null,
        ?string $boundSessionHash = null,
    ): void {
        $this->assertNotLocked($email, $ip);

        // SEC-03: count first, then decide (atomically: a cache lock per counter, fail closed). The requester's own IP key
        // goes FIRST and the address keys only when it passed, so a request refused by its IP limit does not use up the
        // per-address quotas of somebody else's address. A refused request keeps its hit (it is an attempt as well).
        $limits = [
            ['customer-code:ip:'.ClientIpKey::of($ip), self::REQUESTS_PER_IP_PER_HOUR, self::HOUR],
            [$this->requesterKey($email, $ip), self::REQUESTS_PER_EMAIL_PER_HOUR, self::HOUR],
            [$this->dayKey($email), self::REQUESTS_PER_EMAIL_PER_DAY, self::DAY],
        ];

        foreach ($limits as [$key, $max, $decay]) {
            if (AtomicRateLimiter::hit($key, $decay) > $max) {
                throw $this->throttled($key);
            }
        }

        $pauseKey = $this->pauseKey($purpose, $email, $ip);

        if (AtomicRateLimiter::hit($pauseKey, max(1, $this->settings->code_resend_seconds)) > 1) {
            // Too early: a repeated click costs nothing, the quota of the other limits is given back.
            foreach ($limits as [$key, , $decay]) {
                AtomicRateLimiter::decrement($key, $decay);
            }

            throw $this->resendWait($pauseKey);
        }

        if (! $deliver) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sessionHash = $this->sessionHash($binding) ?? ($boundSessionHash !== null && $boundSessionHash !== '' ? $boundSessionHash : null);

        DB::transaction(function () use ($purpose, $email, $ip, $customer, $payload, $code, $sessionHash): void {
            $now = now();

            // Only the open codes of the same session go (RE-01); a code issued without a binding annuls them all.
            CustomerVerificationCode::query()
                ->where('email', $email)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->when($sessionHash !== null, fn ($query) => $query->where('session_hash', $sessionHash))
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
                'session_hash' => $sessionHash,
                'payload' => $payload,
            ])->save();

            // The id is part of the hashed message, so the hash is written after the insert.
            $row->forceFill(['code_hash' => $this->digest($row->id, $purpose, $email, $code)])->save();
        });

        // The mailable is queued (and encrypted in the payload); a mail failure never reaches the request (CA-25).
        Mail::to($email)->queue(new CustomerCodeMail($purpose, $code, max(1, $this->settings->code_ttl_minutes), $customer?->name));
    }

    /**
     * The resend pause of this address, purpose and requester (client IP key); used by callers that must refuse a request that is too early
     * before they spend any limit of their own (the e-mail change of a customer). A pure read.
     *
     * @throws CustomerAuthException the pause is not over (error key `resend`, not the `code` field)
     */
    public function assertPauseOver(VerificationPurpose $purpose, string $email, string $ip): void
    {
        $pauseKey = $this->pauseKey($purpose, $email, $ip);

        if (RateLimiter::tooManyAttempts($pauseKey, 1)) {
            throw $this->resendWait($pauseKey);
        }
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
    public function verify(VerificationPurpose $purpose, string $email, string $input, string $ip, ?string $binding = null): CustomerVerificationCode
    {
        $this->assertNotLocked($email, $ip);

        $ipKey = 'customer-code:entry-ip:'.ClientIpKey::of($ip);

        if (AtomicRateLimiter::hit($ipKey, self::HOUR) > self::ENTRIES_PER_IP_PER_HOUR) {
            throw $this->throttled($ipKey);
        }

        $input = trim($input);
        $sessionHash = $this->sessionHash($binding);
        $row = CustomerVerificationCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose->value)
            ->where(function ($query) use ($sessionHash): void {
                // Only the codes this session asked for (and unbound staff-issued ones) can be entered here.
                $query->whereNull('session_hash');

                if ($sessionHash !== null) {
                    $query->orWhere('session_hash', $sessionHash);
                }
            })
            ->open()
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            // CA-05: an entry without an open code counts like any wrong entry. A code exists in a session only when the
            // account exists, so not counting it would show whether an account exists. The counter and the lock belong
            // to this requester only (address + client IP key), so this cannot lock the real owner out (SEC-04).
            $this->registerWrongEntry($email, $ip);

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
            $this->registerWrongEntry($email, $ip);

            throw $this->invalid();
        }

        $row->attempts++;
        $matches = preg_match('/^\d{6}$/', $input) === 1
            && hash_equals($row->code_hash, $this->digest($row->id, $purpose, $email, $input));

        if (! $matches) {
            if ($row->attempts >= max(1, $this->settings->code_max_attempts)) {
                $this->annul($row);
            }

            $this->registerWrongEntry($email, $ip);

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
     * Seconds until entry and requests of this requester for the address work again; 0 when it is not locked (CA-12).
     * The lock is per requester (address + client IP key, SEC-04): whoever guesses locks only themselves.
     */
    public function lockedFor(string $email, string $ip): int
    {
        $until = Cache::get($this->lockKey($email, $ip));

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    /**
     * Seconds until the next code of this purpose may be requested for the address; 0 when it may be requested now.
     * It is the longest of the 24-hour lock, the hourly request limit of the address and the resend pause, so the
     * countdown on the code pages never disagrees with what the request enforces.
     */
    public function resendAvailableIn(string $email, VerificationPurpose $purpose, string $ip): int
    {
        $waits = [$this->lockedFor($email, $ip)];

        $emailKey = $this->requesterKey($email, $ip);

        if (RateLimiter::tooManyAttempts($emailKey, self::REQUESTS_PER_EMAIL_PER_HOUR)) {
            $waits[] = RateLimiter::availableIn($emailKey);
        }

        if (RateLimiter::tooManyAttempts($this->dayKey($email), self::REQUESTS_PER_EMAIL_PER_DAY)) {
            $waits[] = RateLimiter::availableIn($this->dayKey($email));
        }

        $pauseKey = $this->pauseKey($purpose, $email, $ip);

        if (RateLimiter::tooManyAttempts($pauseKey, 1)) {
            $waits[] = RateLimiter::availableIn($pauseKey);
        }

        return max(0, ...$waits);
    }

    /**
     * Deletes codes that expired more than `$keepDays` days ago (for shop:purge-customer-codes).
     */
    public function purgeExpired(int $keepDays = 1, ?float $deadline = null): int
    {
        $threshold = now()->subDays(max(0, $keepDays));
        $deleted = 0;

        // In chunks, so one run stays within the time limits of shared hosting; the next run continues.
        do {
            $ids = CustomerVerificationCode::query()->where('expires_at', '<', $threshold)->orderBy('id')->limit(1000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += CustomerVerificationCode::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === 1000 && ($deadline === null || microtime(true) < $deadline));

        return $deleted;
    }

    private function assertNotLocked(string $email, string $ip): void
    {
        $left = $this->lockedFor($email, $ip);

        if ($left > 0) {
            throw new CustomerThrottledException($left);
        }
    }

    private function registerWrongEntry(string $email, string $ip): void
    {
        $key = 'customer-code:wrong:'.$this->hash($email.'|'.ClientIpKey::of($ip));
        $count = AtomicRateLimiter::hit($key, self::DAY);

        if ($count < self::WRONG_ENTRIES_PER_DAY) {
            return;
        }

        // Engage the lock once; later wrong entries cannot happen while it holds.
        $until = now()->getTimestamp() + self::DAY;

        if (! Cache::add($this->lockKey($email, $ip), $until, self::DAY)) {
            return;
        }

        // The warning goes to a real owner only, and at most once per address per 24 hours (several requesters cannot
        // turn it into a mail flood). Which branch ran is invisible to the requester.
        $owner = Customer::query()->where('email', $email)->exists();

        if ($owner && Cache::add('customer-code:guess-mail:'.$this->hash($email), 1, self::DAY)) {
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

    /**
     * The resend pause is per requester (RE-01): a stranger's request does not start the owner's pause.
     */
    private function pauseKey(VerificationPurpose $purpose, string $email, string $ip): string
    {
        return 'customer-code:pause:'.$purpose->value.':'.$this->hash($email.'|'.ClientIpKey::of($ip));
    }

    private function requesterKey(string $email, string $ip): string
    {
        return 'customer-code:email:'.$this->hash($email.'|'.ClientIpKey::of($ip));
    }

    private function lockKey(string $email, string $ip): string
    {
        return 'customer-code:lock:'.$this->hash($email.'|'.ClientIpKey::of($ip));
    }

    private function dayKey(string $email): string
    {
        return 'customer-code:day:'.$this->hash($email);
    }

    private function throttled(string $key): CustomerThrottledException
    {
        return new CustomerThrottledException(max(1, RateLimiter::availableIn($key)));
    }

    private function resendWait(string $pauseKey): CustomerAuthException
    {
        return new CustomerAuthException(__('account.errors.resend_wait', ['time' => CustomerThrottledException::humanTime(RateLimiter::availableIn($pauseKey))]), 'resend');
    }

    /**
     * The stored form of the session token; null for a code that is not bound to a session.
     */
    private function sessionHash(?string $binding): ?string
    {
        return $binding === null || $binding === '' ? null : hash('sha256', $binding);
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
