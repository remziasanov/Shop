<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\PhoneVerificationBehavior;
use App\Enums\PhoneVerificationDriver;
use App\Enums\PhoneVerificationError;
use App\Enums\PhoneVerificationMethod;
use App\Enums\PhoneVerificationMode;
use App\Enums\PhoneVerificationOutcome;
use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;
use App\Exceptions\PhoneVerificationException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Users\AuditLogger;
use App\Settings\PhoneVerificationSettings;
use App\Support\ClientIpKey;
use App\Support\PhoneNormalizer;
use App\Support\PiiMask;
use App\Support\ShopName;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Phone number verification by a flash call or an SMS code (PV-03…PV-06, D-110). Business rules only: the provider sits
 * behind {@see PhoneVerificationGateway}, the screens and the integration into forms (PV-13) live elsewhere.
 *
 *  - The code is 4 digits (`random_int`; with a driver that returns its own code, that one). Only an HMAC-SHA256 over
 *    `id|purpose|phone|session binding|code` is stored (the approach of the e-mail codes, CA-04), so a code cannot be read
 *    back and is worthless in another session (D-108). It lives `code_ttl_minutes`, takes 5 entries and is single use.
 *  - One provider call per request, never a call and an SMS in one request, no retries. The SMS follows a call only on
 *    {@see requestSms()} (the customer's button, not earlier than 60 s after the call).
 *  - Limits (RateLimiter on the cache, NF-53): per number 1 per 60 s, 3 per hour, 6 per day; per IP (IPv6 by /64) and per
 *    session 10 per hour, per IP 15 per day (SEC-01, D-198); every limit is counted first and judged afterwards (SEC-03).
 *    Entries: 20 per hour per IP; 15 wrong guesses per day per requester (number + IP) lock that requester out of the
 *    number for 24 h (SEC-04, D-200). The shop's daily limit is counted in the database (rows accepted by the provider);
 *    guests may use only {@see GUEST_SHARE_PERCENT} % of it (SEC-01).
 *  - Only a provider failure of the PV-05 list (network, timeout, 5xx, authorization) may skip a verification, and only
 *    when the owner chose so. Limits, a wrong code, an expired code, too many attempts, a zero balance never skip.
 *  - A verified number is remembered in the session for 24 hours and for the same purpose ({@see isVerified()}).
 *
 * Phone numbers appear in logs only masked ({@see PiiMask}); codes never.
 */
final class PhoneVerificationService
{
    public const BINDING_KEY = 'phone_verification.binding';

    public const VERIFIED_KEY = 'phone_verification.verified';

    public const CODE_LENGTH = 4;

    public const MAX_ATTEMPTS = 5;

    public const PAUSE_SECONDS = 60;

    public const PER_NUMBER_PER_HOUR = 3;

    public const PER_NUMBER_PER_DAY = 6;

    public const PER_IP_PER_HOUR = 10;

    public const PER_SESSION_PER_HOUR = 10;

    /** SEC-01, D-198: starts per 24 h from one IP (IPv6: /64). */
    public const PER_IP_PER_DAY = 15;

    /** SEC-01, D-198: the share of `daily_limit` guests may use; the rest is for signed-in customers and known buyers. */
    public const GUEST_SHARE_PERCENT = 80;

    public const ENTRIES_PER_IP_PER_HOUR = 20;

    public const WRONG_ENTRIES_PER_DAY = 15;

    public const VERIFIED_SECONDS = 86400;

    public const RETENTION_DAYS = 90;

    private const HOUR = 3600;

    private const DAY = 86400;

    public function __construct(
        private readonly PhoneVerificationSettings $settings,
        private readonly ShopAlerts $alerts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Verification is on and usable: the switch is on and the driver is allowed in this environment.
     */
    public function isEnabled(): bool
    {
        $driver = PhoneVerificationDriver::tryFrom($this->settings->driver);

        return $this->settings->enabled && $driver !== null && GatewayFactory::isAllowed($driver);
    }

    /**
     * The canonical +7XXXXXXXXXX form (SF-44 {@see PhoneNormalizer}); numbers of other +7 countries that the SF-44 rule
     * does not know (Kazakhstan +77…) are accepted here in the same form, whether they are allowed is up to the prefixes.
     */
    public static function canonical(string $phone): ?string
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized !== null) {
            return $normalized;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[3-9]\d{9}$/', $digits) === 1 ? '+7'.$digits : null;
    }

    /**
     * The phone of a contact form (checkout, registration, profile, wholesale application): the SF-44 rule first, so
     * nothing changes for numbers it knows. Where verification is on, a number the SF-44 rule does not know (Kazakhstan
     * +77…) is accepted in its canonical form too, but only when an allowed prefix covers it (PV-06, D-157, D-173).
     *
     * @return string|null the canonical +7XXXXXXXXXX form, null when the number is not acceptable
     */
    public function contactPhone(?string $input): ?string
    {
        $normalized = PhoneNormalizer::normalize($input);

        if ($normalized !== null || $input === null || ! $this->isEnabled()) {
            return $normalized;
        }

        try {
            return $this->normalize($input);
        } catch (PhoneVerificationException) {
            return null;
        }
    }

    /**
     * The canonical +7XXXXXXXXXX form of a number that passes the allowed prefixes (PV-06).
     *
     * @throws PhoneVerificationException `invalid_phone` or `prefix_not_allowed`
     */
    public function normalize(string $phone): string
    {
        $normalized = self::canonical($phone);

        if ($normalized === null) {
            throw new PhoneVerificationException(PhoneVerificationError::InvalidPhone);
        }

        foreach ($this->settings->allowed_prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return $normalized;
            }
        }

        throw new PhoneVerificationException(PhoneVerificationError::PrefixNotAllowed);
    }

    /**
     * Sends the first code: a flash call, or an SMS in the «только SMS» mode (PV-03, PV-04).
     *
     * @throws PhoneVerificationException a limit (HTTP 429), the daily limit, a block on provider failure or a zero balance
     */
    public function start(string $phone, PhoneVerificationPurpose $purpose, Session $session, string $ip, ?Customer $customer = null): PhoneVerificationStart
    {
        $this->assertEnabled();
        $phone = $this->normalize($phone);
        $method = PhoneVerificationMode::tryFrom($this->settings->mode) === PhoneVerificationMode::Sms
            ? PhoneVerificationMethod::Sms
            : PhoneVerificationMethod::FlashCall;

        return $this->send($phone, $purpose, $method, $session, $ip, $customer);
    }

    /**
     * «Получить SMS» (PV-04): only in the mode «звонок, а SMS — по кнопке», only for a number that was called in this session
     * for this purpose, not earlier than 60 s after the call, and always as its own request.
     *
     * @throws PhoneVerificationException `sms_not_available`, `sms_too_early` (429) or any error of {@see start()}
     */
    public function requestSms(string $phone, PhoneVerificationPurpose $purpose, Session $session, string $ip, ?Customer $customer = null): PhoneVerificationStart
    {
        $this->assertEnabled();

        if (PhoneVerificationMode::tryFrom($this->settings->mode) !== PhoneVerificationMode::FlashCallThenSms) {
            throw new PhoneVerificationException(PhoneVerificationError::SmsNotAvailable);
        }

        $phone = $this->normalize($phone);
        $binding = $this->binding($session);

        /** @var PhoneVerification|null $call */
        $call = PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->where('session_hash', $binding)
            ->where('method', PhoneVerificationMethod::FlashCall->value)
            ->open()
            ->orderByDesc('id')
            ->first();

        if ($call === null || $call->created_at === null) {
            throw new PhoneVerificationException(PhoneVerificationError::SmsNotAvailable);
        }

        // The SMS goes once per call: a newer open code of this session means it has been requested already.
        $later = PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->where('session_hash', $binding)
            ->where('id', '>', $call->id)
            ->exists();

        if ($later) {
            throw new PhoneVerificationException(PhoneVerificationError::SmsNotAvailable);
        }

        $wait = self::PAUSE_SECONDS - (int) $call->created_at->diffInSeconds(now(), true);

        if ($wait > 0) {
            throw PhoneVerificationException::throttled($wait, PhoneVerificationError::SmsTooEarly);
        }

        return $this->send($phone, $purpose, PhoneVerificationMethod::Sms, $session, $ip, $customer);
    }

    /**
     * Checks the entered code (PV-03). Success marks the number as verified in this session for this purpose.
     *
     * @return PhoneVerification the verified record (`customer_id`, `method`)
     *
     * @throws PhoneVerificationException the IP limit or the 24-hour lock (429); `no_active_code`, `expired`,
     *                                    `too_many_attempts`, `wrong_code` (field `code`)
     */
    public function verify(string $phone, PhoneVerificationPurpose $purpose, string $code, Session $session, string $ip): PhoneVerification
    {
        $phone = $this->normalize($phone);
        $this->assertNotLocked($phone, $ip);

        $ipKey = 'phone-verification:entry-ip:'.ClientIpKey::of($ip);

        if (RateLimiter::tooManyAttempts($ipKey, self::ENTRIES_PER_IP_PER_HOUR)) {
            throw PhoneVerificationException::throttled(RateLimiter::availableIn($ipKey));
        }

        RateLimiter::hit($ipKey, self::HOUR);

        $binding = $this->binding($session);
        $input = trim($code);

        /** @var PhoneVerification|null $row */
        $row = PhoneVerification::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->where('session_hash', $binding)
            ->where('outcome', PhoneVerificationOutcome::Pending->value)
            ->orderByDesc('id')
            ->first();

        // SEC-04: only a real guess against an open code counts. Without an open challenge of THIS session (the query is
        // bound to the session that started the code) nobody can guess anything, so nothing is counted and an outsider
        // cannot burn the number's budget.
        if ($row === null) {
            throw new PhoneVerificationException(PhoneVerificationError::NoActiveCode);
        }

        if ($row->expires_at->lte(now())) {
            $this->finish($row, PhoneVerificationOutcome::Expired);

            throw new PhoneVerificationException(PhoneVerificationError::Expired);
        }

        // Atomic: two parallel requests cannot spend more than the allowed number of attempts.
        $counted = PhoneVerification::query()
            ->whereKey($row->id)
            ->where('outcome', PhoneVerificationOutcome::Pending->value)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($counted === 0) {
            $this->finish($row, PhoneVerificationOutcome::Failed);

            throw new PhoneVerificationException(PhoneVerificationError::TooManyAttempts);
        }

        $row->attempts++;
        $matches = preg_match('/^\d{'.self::CODE_LENGTH.'}$/', $input) === 1
            && $row->code_hash !== null
            && hash_equals($row->code_hash, $this->digest($row->id, $purpose, $phone, $binding, $input));

        if (! $matches) {
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                $this->finish($row, PhoneVerificationOutcome::Failed);
            }

            $this->registerWrongEntry($phone, $ip);

            throw new PhoneVerificationException($row->attempts >= self::MAX_ATTEMPTS ? PhoneVerificationError::TooManyAttempts : PhoneVerificationError::WrongCode);
        }

        // Single use: only the request that flips the outcome wins.
        $won = PhoneVerification::query()
            ->whereKey($row->id)
            ->where('outcome', PhoneVerificationOutcome::Pending->value)
            ->update(['outcome' => PhoneVerificationOutcome::Verified->value, 'verified_at' => now(), 'updated_at' => now()]);

        if ($won !== 1) {
            throw new PhoneVerificationException(PhoneVerificationError::NoActiveCode);
        }

        $row->forceFill(['outcome' => PhoneVerificationOutcome::Verified, 'verified_at' => now()])->syncOriginal();

        $verified = (array) $session->get(self::VERIFIED_KEY, []);
        $verified[$purpose->value] = ['phone' => $this->phoneMark($phone), 'until' => now()->getTimestamp() + self::VERIFIED_SECONDS];
        $session->put(self::VERIFIED_KEY, $verified);

        return $row;
    }

    /**
     * Whether this number was verified in this session for this purpose within the last 24 hours (PV-07, PV-13 г). The
     * number is compared in its canonical form, so any spelling of the same number matches.
     */
    public function isVerified(string $phone, PhoneVerificationPurpose $purpose, Session $session): bool
    {
        $normalized = self::canonical($phone);
        $entry = ((array) $session->get(self::VERIFIED_KEY, []))[$purpose->value] ?? null;

        if ($normalized === null || ! is_array($entry) || ! is_int($entry['until'] ?? null) || ! is_string($entry['phone'] ?? null)) {
            return false;
        }

        return $entry['until'] > now()->getTimestamp() && hash_equals($entry['phone'], $this->phoneMark($normalized));
    }

    /**
     * Forgets the verified number of this purpose in the session (the phone changed after the verification, PV-13 г).
     */
    public function forget(PhoneVerificationPurpose $purpose, Session $session): void
    {
        $verified = (array) $session->get(self::VERIFIED_KEY, []);
        unset($verified[$purpose->value]);

        if ($verified === []) {
            $session->forget(self::VERIFIED_KEY);

            return;
        }

        $session->put(self::VERIFIED_KEY, $verified);
    }

    /**
     * «Отправить тест на мой номер» (PV-01): the same limits and the daily limit as any request; a provider failure is
     * always reported (never skipped); the action goes to the journal (RB-12, no phone number in it).
     *
     * @throws PhoneVerificationException
     */
    public function sendTest(User $actor, string $phone, Session $session, string $ip): PhoneVerificationStart
    {
        try {
            $start = $this->start($phone, PhoneVerificationPurpose::Test, $session, $ip);
        } catch (PhoneVerificationException $e) {
            $this->audit->record(AuditAction::PhoneVerificationTest, $actor, null, ['result' => $e->reason->value]);

            throw $e;
        }

        $this->audit->record(AuditAction::PhoneVerificationTest, $actor, null, ['result' => 'sent', 'method' => $start->method->value]);

        return $start;
    }

    /**
     * Provider-accepted requests of today, counted in the database (PV-06).
     */
    public function dailyUsage(): int
    {
        return PhoneVerification::query()->acceptedByProvider()->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Seconds until the number may be asked for another code (the pause of PV-06); 0 when it may be asked now.
     */
    public function resendAvailableIn(string $phone): int
    {
        $normalized = self::canonical($phone);

        if ($normalized === null) {
            return 0;
        }

        $key = $this->numberKey('pause', $normalized);

        return RateLimiter::tooManyAttempts($key, 1) ? max(0, RateLimiter::availableIn($key)) : 0;
    }

    /**
     * Records still `pending` past their expiry become `expired` (the journal then reads right).
     */
    public function expireStale(): int
    {
        return PhoneVerification::query()
            ->where('outcome', PhoneVerificationOutcome::Pending->value)
            ->where('expires_at', '<', now())
            ->update(['outcome' => PhoneVerificationOutcome::Expired->value, 'updated_at' => now()]);
    }

    /**
     * Deletes records older than `$days` days (shop:cleanup, PV-08), in chunks within the time budget.
     */
    public function purgeOlderThan(int $days = self::RETENTION_DAYS, ?float $deadline = null): int
    {
        $this->expireStale();

        $threshold = now()->subDays(max(0, $days));
        $deleted = 0;

        do {
            $ids = PhoneVerification::query()->where('created_at', '<', $threshold)->orderBy('id')->limit(1000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += PhoneVerification::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === 1000 && ($deadline === null || microtime(true) < $deadline));

        return $deleted;
    }

    /**
     * Anonymization of a customer (CA-17, PV-08): the records linked to the customer and the records of the customer's
     * own number are deleted.
     */
    public function deleteForCustomer(Customer $customer): int
    {
        $phone = self::canonical((string) $customer->phone);

        return PhoneVerification::query()
            ->where(function ($query) use ($customer, $phone): void {
                $query->where('customer_id', $customer->id);

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->delete();
    }

    /**
     * One provider call for one request: limits, daily limit, the call, the record.
     */
    private function send(string $phone, PhoneVerificationPurpose $purpose, PhoneVerificationMethod $method, Session $session, string $ip, ?Customer $customer): PhoneVerificationStart
    {
        $this->assertSingleProviderCall();

        $binding = $this->binding($session);

        $this->assertNotLocked($phone, $ip);
        $this->consumeLimits($phone, $ip, $binding);

        // The daily limit of the shop is counted in the database, not in the cache (PV-06). A refused start leaves no
        // accepted row, so it never burns this budget (D-199). Guests stop at their share (SEC-01).
        if ($this->dailyBudgetExhausted($phone, $purpose, $customer)) {
            $this->alerts->dailyLimit($this->settings->daily_limit);

            if ($purpose !== PhoneVerificationPurpose::Test && $this->behavior($this->settings->on_daily_limit, PhoneVerificationBehavior::Block) === PhoneVerificationBehavior::Skip) {
                return $this->skipped($this->unsent($phone, $purpose, $method, $binding, $ip, $customer, PhoneVerificationOutcome::SkippedProvider, 'daily_limit'));
            }

            $this->unsent($phone, $purpose, $method, $binding, $ip, $customer, PhoneVerificationOutcome::Blocked, 'daily_limit');

            throw new PhoneVerificationException(PhoneVerificationError::DailyLimit);
        }

        try {
            $gateway = app(PhoneVerificationGateway::class);

            if ($method === PhoneVerificationMethod::FlashCall) {
                $call = $gateway->startFlashCall($phone, $gateway->supportsCallerCode() ? $this->randomCode() : null);
                [$code, $requestId, $cost] = [$call->code, $call->requestId, $call->cost];
            } else {
                $code = $this->randomCode();
                $sms = $gateway->sendSms($phone, SmsTemplate::render($this->settings->sms_template, $code, ShopName::get()));
                [$requestId, $cost] = [$sms->requestId, $sms->cost];
            }
        } catch (PhoneProviderFailure $e) {
            return $this->providerFailed($e, $phone, $purpose, $method, $binding, $ip, $customer);
        } catch (PhoneProviderRefusal $e) {
            $this->unsent($phone, $purpose, $method, $binding, $ip, $customer, PhoneVerificationOutcome::Blocked, $e->insufficientBalance ? 'balance' : 'refused');
            Log::warning('Phone verification refused by the provider', ['phone' => PiiMask::phone($phone), 'balance' => $e->insufficientBalance, 'message' => PiiMask::digitRuns($e->getMessage())]);

            if ($e->insufficientBalance) {
                $this->alerts->emptyBalance();
            }

            throw new PhoneVerificationException(PhoneVerificationError::ProviderRefused);
        }

        $row = $this->store($phone, $purpose, $method, $binding, $ip, $customer, $code, $requestId, $cost);

        return new PhoneVerificationStart(
            skipped: false,
            phone: $phone,
            method: $method,
            verification: $row,
            resendAfter: self::PAUSE_SECONDS,
            canRequestSms: $method === PhoneVerificationMethod::FlashCall
                && PhoneVerificationMode::tryFrom($this->settings->mode) === PhoneVerificationMode::FlashCallThenSms,
            expiresAt: $row->expires_at,
        );
    }

    /**
     * PV-05: at most one provider call per HTTP request (a Livewire request may carry several component updates).
     */
    private function assertSingleProviderCall(): void
    {
        // Console runs (artisan, queue, tests) have no HTTP request: several calls there are separate operations.
        if (app()->runningInConsole()) {
            return;
        }

        /** @var \WeakMap<object, true>|null $seen */
        static $seen = null;
        $seen ??= new \WeakMap;
        $request = app('request');

        if (isset($seen[$request])) {
            throw PhoneVerificationException::throttled(60);
        }

        $seen[$request] = true;
    }

    private function providerFailed(PhoneProviderFailure $e, string $phone, PhoneVerificationPurpose $purpose, PhoneVerificationMethod $method, string $binding, string $ip, ?Customer $customer): PhoneVerificationStart
    {
        Log::warning('Phone verification provider failure', ['kind' => $e->kind->value, 'phone' => PiiMask::phone($phone), 'message' => PiiMask::digitRuns($e->getMessage())]);
        $this->alerts->providerFailure($e->kind->value);

        // The test button always reports the failure.
        $behavior = $purpose === PhoneVerificationPurpose::Test
            ? PhoneVerificationBehavior::Block
            : $this->behavior($this->settings->on_provider_failure, PhoneVerificationBehavior::Skip);

        if ($behavior === PhoneVerificationBehavior::Skip) {
            return $this->skipped($this->unsent($phone, $purpose, $method, $binding, $ip, $customer, PhoneVerificationOutcome::SkippedProvider, $e->kind->value));
        }

        $this->unsent($phone, $purpose, $method, $binding, $ip, $customer, PhoneVerificationOutcome::Blocked, $e->kind->value);

        throw new PhoneVerificationException(PhoneVerificationError::ProviderUnavailable);
    }

    private function skipped(PhoneVerification $row): PhoneVerificationStart
    {
        return new PhoneVerificationStart(true, $row->phone, $row->method, $row, 0, false, null);
    }

    private function behavior(string $value, PhoneVerificationBehavior $default): PhoneVerificationBehavior
    {
        return PhoneVerificationBehavior::tryFrom($value) ?? $default;
    }

    /**
     * Number, IP and session limits of PV-06 (SEC-01, SEC-03, D-198, D-199). Every limit is COUNTED FIRST and judged on the
     * returned count (`hit() > max`), so parallel requests cannot all pass a check made before any of them counted.
     * The limits are applied in three gates, and a refusal stops at its gate: (1) the requester's own limits (IP per
     * hour, IP per day, session) - a refused outsider never touches the counters of a number he asks for; (2) the pause
     * of the number; (3) the number's hour and day limits. Counters of the gates passed stay counted (that is what makes
     * a burst fail). The shop-wide daily budget is not a counter, it is read from accepted rows, so a refusal never
     * burns it. Throws with the longest wait of the limits that hold in the failing gate.
     */
    private function consumeLimits(string $phone, string $ip, string $binding): void
    {
        $ipKey = ClientIpKey::of($ip);

        $this->hitGate([
            ['phone-verification:ip:'.$ipKey, self::PER_IP_PER_HOUR, self::HOUR],
            ['phone-verification:ip-day:'.$ipKey, self::PER_IP_PER_DAY, self::DAY],
            ['phone-verification:session:'.$binding, self::PER_SESSION_PER_HOUR, self::HOUR],
        ]);
        $this->hitGate([[$this->numberKey('pause', $phone), 1, self::PAUSE_SECONDS]]);
        $this->hitGate([
            [$this->numberKey('hour', $phone), self::PER_NUMBER_PER_HOUR, self::HOUR],
            [$this->numberKey('day', $phone), self::PER_NUMBER_PER_DAY, self::DAY],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int}>  $limits  key, maximum, window in seconds
     */
    private function hitGate(array $limits): void
    {
        $wait = 0;

        foreach ($limits as [$key, $max, $decay]) {
            if (RateLimiter::hit($key, $decay) > $max) {
                $wait = max($wait, RateLimiter::availableIn($key), 1);
            }
        }

        if ($wait > 0) {
            throw PhoneVerificationException::throttled($wait);
        }
    }

    /**
     * The shop's daily budget is spent for this request: all of `daily_limit`, or, for a guest, the guest share of it
     * (SEC-01). Signed-in customers, numbers with a completed order and the admin test use the whole budget.
     */
    private function dailyBudgetExhausted(string $phone, PhoneVerificationPurpose $purpose, ?Customer $customer): bool
    {
        $limit = max(0, $this->settings->daily_limit);
        $usage = $this->dailyUsage();

        if ($usage >= $limit) {
            return true;
        }

        if ($customer !== null || $purpose === PhoneVerificationPurpose::Test) {
            return false;
        }

        $guestCap = $limit > 0 ? max(1, intdiv($limit * self::GUEST_SHARE_PERCENT, 100)) : 0;

        return $usage >= $guestCap && ! $this->hasCompletedOrder($phone);
    }

    /**
     * A number that bought something already (status `completed`: «Выполнен» / «Выкуплен»). Cancelled, expired, closed
     * inquiries and orders still in progress do not count: they cost the shop nothing to fake.
     */
    private function hasCompletedOrder(string $phone): bool
    {
        return Order::query()
            ->where('customer_phone', $phone)
            ->where('status', OrderStatus::Completed->value)
            ->exists();
    }

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new PhoneVerificationException(PhoneVerificationError::Disabled);
        }
    }

    private function assertNotLocked(string $phone, string $ip): void
    {
        $until = Cache::get($this->lockKey($phone, $ip));
        $left = is_int($until) ? $until - now()->getTimestamp() : 0;

        if ($left > 0) {
            throw PhoneVerificationException::throttled($left, PhoneVerificationError::NumberLocked);
        }
    }

    /**
     * 15 wrong guesses a day from one requester (IP, IPv6 by /64) lock that requester out of the number for 24 hours
     * (PV-03, SEC-04, D-200): an outsider can lock only himself, never the owner of the number.
     */
    private function registerWrongEntry(string $phone, string $ip): void
    {
        $count = RateLimiter::hit($this->numberKey('wrong', $phone).':'.sha1(ClientIpKey::of($ip)), self::DAY);

        if ($count >= self::WRONG_ENTRIES_PER_DAY) {
            Cache::add($this->lockKey($phone, $ip), now()->getTimestamp() + self::DAY, self::DAY);
        }
    }

    private function finish(PhoneVerification $row, PhoneVerificationOutcome $outcome): void
    {
        PhoneVerification::query()
            ->whereKey($row->id)
            ->where('outcome', PhoneVerificationOutcome::Pending->value)
            ->update(['outcome' => $outcome->value, 'updated_at' => now()]);
    }

    private function store(string $phone, PhoneVerificationPurpose $purpose, PhoneVerificationMethod $method, string $binding, string $ip, ?Customer $customer, string $code, string $requestId, ?string $cost): PhoneVerification
    {
        return DB::transaction(function () use ($phone, $purpose, $method, $binding, $ip, $customer, $code, $requestId, $cost): PhoneVerification {
            // A new code replaces the open ones of this number, purpose and session.
            PhoneVerification::query()
                ->where('phone', $phone)
                ->where('purpose', $purpose->value)
                ->where('session_hash', $binding)
                ->where('outcome', PhoneVerificationOutcome::Pending->value)
                ->update(['outcome' => PhoneVerificationOutcome::Expired->value, 'updated_at' => now()]);

            $row = new PhoneVerification;
            $row->forceFill([
                'phone' => $phone,
                'purpose' => $purpose,
                'method' => $method,
                'code_hash' => null,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(max(1, $this->settings->code_ttl_minutes)),
                'outcome' => PhoneVerificationOutcome::Pending,
                'session_hash' => $binding,
                'provider_request_id' => mb_substr($requestId, 0, 64),
                'cost' => $cost,
                'ip' => $ip,
                'customer_id' => $customer?->id,
            ])->save();

            // The id is part of the hashed message, so the hash is written after the insert.
            $row->forceFill(['code_hash' => $this->digest($row->id, $purpose, $phone, $binding, $code)])->save();

            return $row;
        });
    }

    /**
     * A record without a sent code: a skipped (provider failure, daily limit) or blocked request.
     */
    private function unsent(string $phone, PhoneVerificationPurpose $purpose, PhoneVerificationMethod $method, string $binding, string $ip, ?Customer $customer, PhoneVerificationOutcome $outcome, string $note): PhoneVerification
    {
        $row = new PhoneVerification;
        $row->forceFill([
            'phone' => $phone,
            'purpose' => $purpose,
            'method' => $method,
            'code_hash' => null,
            'attempts' => 0,
            'expires_at' => now(),
            'outcome' => $outcome,
            'session_hash' => $binding,
            'provider_request_id' => null,
            'cost' => null,
            'ip' => $ip,
            'customer_id' => $customer?->id,
            'note' => $note,
        ])->save();

        return $row;
    }

    /**
     * The hash of the random session token that binds codes to the session that asked for them (D-108).
     */
    private function binding(Session $session): string
    {
        $token = $session->get(self::BINDING_KEY);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $session->put(self::BINDING_KEY, $token);
        }

        return hash('sha256', $token);
    }

    private function randomCode(): string
    {
        return str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * HMAC-SHA256 with the application key over `id|purpose|phone|binding|code` (the approach of CA-04).
     */
    private function digest(int $id, PhoneVerificationPurpose $purpose, string $phone, string $binding, string $code): string
    {
        return hash_hmac('sha256', $id.'|'.$purpose->value.'|'.$phone.'|'.$binding.'|'.$code, app('encrypter')->getKey());
    }

    /**
     * What the session keeps instead of the number itself.
     */
    private function phoneMark(string $phone): string
    {
        return hash_hmac('sha256', 'phone|'.$phone, app('encrypter')->getKey());
    }

    private function numberKey(string $kind, string $phone): string
    {
        return 'phone-verification:'.$kind.':'.sha1($phone);
    }

    private function lockKey(string $phone, string $ip): string
    {
        return 'phone-verification:lock:'.sha1($phone).':'.sha1(ClientIpKey::of($ip));
    }
}
