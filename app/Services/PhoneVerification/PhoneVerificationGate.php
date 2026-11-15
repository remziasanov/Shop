<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\PhoneVerificationGuestScope;
use App\Enums\PhoneVerificationPurpose;
use App\Enums\PhoneVerificationRegistrationMode;
use App\Exceptions\PhoneVerificationException;
use App\Exceptions\SalesException;
use App\Models\Customer;
use App\Models\TelegramAccount;
use App\Services\Orders\OrderService;
use App\Settings\PhoneVerificationSettings;
use App\Support\TelegramMode;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Where phone verification meets the existing processes (PV-07, PV-13). The service ({@see PhoneVerificationService})
 * sends and checks codes; this class decides WHEN a number has to be verified and whether it already is:
 *
 *  - guest checkout scenarios (all / reservations / orders over an amount, PV-13 д) and the «registration: required»
 *    lock of the account (PV-13 а);
 *  - «verified» = the number of the signed-in customer's verified profile (PV-13 в), the verified number of the Telegram
 *    account of the Mini App (TG-09), or the number verified in this session for this purpose in the last 24 hours;
 *  - a provider failure that the owner chose to skip leaves a short-lived mark in the session, so the request can go on
 *    WITHOUT a verified number (`orders.phone_verified = false`, PV-05).
 *
 * Everything here is server side: the forms only ask, {@see OrderService} checks again inside the
 * checkout transaction (PV-13 г).
 */
final class PhoneVerificationGate
{
    /** Session key of the «provider failed, verification skipped» marks: purpose => {phone mark, until}. */
    public const SKIPPED_KEY = 'phone_verification.skipped';

    /** How long a skip mark lives: enough to finish the form, too short to be reused later. */
    private const SKIP_SECONDS = 1800;

    public function __construct(
        private readonly PhoneVerificationSettings $settings,
        private readonly PhoneVerificationService $service,
    ) {}

    /**
     * The session store of the current request (also inside Livewire updates, where the request has none attached).
     */
    public static function session(): Session
    {
        /** @var Session $store */
        $store = app('session.store');

        return $store;
    }

    // ------------------------------------------------------------------ scenarios (PV-07)

    public function isEnabled(): bool
    {
        return $this->service->isEnabled();
    }

    public function registrationMode(): PhoneVerificationRegistrationMode
    {
        if (! $this->isEnabled()) {
            return PhoneVerificationRegistrationMode::Off;
        }

        return PhoneVerificationRegistrationMode::tryFrom($this->settings->registration) ?? PhoneVerificationRegistrationMode::Off;
    }

    public function guestScope(): PhoneVerificationGuestScope
    {
        if (! $this->isEnabled()) {
            return PhoneVerificationGuestScope::Off;
        }

        return PhoneVerificationGuestScope::tryFrom($this->settings->guest_checkout) ?? PhoneVerificationGuestScope::Off;
    }

    public function profileChangeEnabled(): bool
    {
        return $this->isEnabled() && $this->settings->profile_change;
    }

    public function wholesaleEnabled(): bool
    {
        return $this->isEnabled() && $this->settings->wholesale;
    }

    /**
     * PV-13 а: «registration: required» — the customer signs in, but places no request under the account until the
     * phone is verified.
     */
    public function accountBlocked(?Customer $customer): bool
    {
        if ($customer === null || $customer->phone_verified_at !== null || $this->registrationMode() !== PhoneVerificationRegistrationMode::Required) {
            return false;
        }

        // PV-05: the provider failed while the customer verified the profile phone and the owner skips: the lock is
        // lifted for this session (the requests then carry `phone_verified = false`).
        return ! ($customer->phone !== null && $this->wasSkipped($customer->phone, PhoneVerificationPurpose::Registration, self::session()));
    }

    /**
     * The cabinet offers (later) or demands (required) the verification of the profile phone.
     */
    public function accountPrompt(?Customer $customer): bool
    {
        return $customer !== null && $customer->phone_verified_at === null && $this->registrationMode() !== PhoneVerificationRegistrationMode::Off;
    }

    /**
     * Whether a request of a GUEST needs a verified phone (PV-07, PV-13 д). A mixed checkout is one verification: the
     * amount threshold looks at the «order» part only, the reservation part follows «only reservations».
     *
     * @param  int  $orderPartTotal  kopecks, the items of the «order» part (0 without one)
     */
    public function guestNeedsVerification(?Customer $customer, bool $hasOrder, bool $hasReservation, bool $hasInquiry, int $orderPartTotal): bool
    {
        if ($customer !== null) {
            return false;
        }

        return match ($this->guestScope()) {
            PhoneVerificationGuestScope::All => $hasOrder || $hasReservation || $hasInquiry,
            PhoneVerificationGuestScope::Reservations => $hasReservation,
            PhoneVerificationGuestScope::OrdersOver => $hasOrder && $orderPartTotal >= max(0, $this->settings->guest_checkout_min_amount),
            PhoneVerificationGuestScope::Off => false,
        };
    }

    // ------------------------------------------------------------------ «is it verified»

    /**
     * PV-13 в, TG-09, PV-07: the number is verified without a call (the signed-in customer's own verified phone, the
     * verified phone of the Telegram account) or by a code entered in this session for this purpose within 24 hours.
     */
    public function isPhoneVerified(string $phone, PhoneVerificationPurpose $purpose, ?Customer $customer, ?Session $session): bool
    {
        $canonical = PhoneVerificationService::canonical($phone);

        if ($canonical === null) {
            return false;
        }

        if ($customer !== null && $customer->phone_verified_at !== null && $customer->phone !== null && PhoneVerificationService::canonical($customer->phone) === $canonical) {
            return true;
        }

        if ($this->isTelegramVerified($canonical, $session)) {
            return true;
        }

        return $session !== null && $this->service->isVerified($canonical, $purpose, $session);
    }

    /**
     * TG-09: the Mini App session belongs to a Telegram account whose verified phone is this number.
     */
    public function isTelegramVerified(string $phone, ?Session $session = null): bool
    {
        $canonical = PhoneVerificationService::canonical($phone);
        $accountId = TelegramMode::accountId($session);

        if ($canonical === null || $accountId === null) {
            return false;
        }

        $account = TelegramAccount::query()->whereKey($accountId)->whereNotNull('phone_verified_at')->first(['id', 'phone']);

        return $account !== null && is_string($account->phone) && PhoneVerificationService::canonical($account->phone) === $canonical;
    }

    /**
     * PV-13 г: the verified number of this purpose is forgotten when the typed number is another one.
     */
    public function forgetIfChanged(string $phone, PhoneVerificationPurpose $purpose, Session $session): void
    {
        if (! $this->service->isVerified($phone, $purpose, $session)) {
            $this->service->forget($purpose, $session);
        }
    }

    // ------------------------------------------------------------------ provider failure that is skipped (PV-05)

    /**
     * Starts a verification for a form that is valid already (PV-13 б). When the provider failed and the owner chose
     * «пропускать», the mark is stored and `skipped` is true: the caller goes on without a verified number.
     *
     * @throws PhoneVerificationException
     */
    public function begin(string $phone, PhoneVerificationPurpose $purpose, Session $session, string $ip, ?Customer $customer): PhoneVerificationStart
    {
        $start = $this->service->start($phone, $purpose, $session, $ip, $customer);

        if ($start->skipped) {
            $this->markSkipped($start->phone, $purpose, $session);
        }

        return $start;
    }

    public function markSkipped(string $phone, PhoneVerificationPurpose $purpose, Session $session): void
    {
        $marks = (array) $session->get(self::SKIPPED_KEY, []);
        $marks[$purpose->value] = ['phone' => $this->mark($phone), 'until' => now()->getTimestamp() + self::SKIP_SECONDS];
        $session->put(self::SKIPPED_KEY, $marks);
    }

    /**
     * A skip mark is single use (PV-05): only the request created on the skip path goes unverified.
     */
    public function forgetSkipped(PhoneVerificationPurpose $purpose, ?Session $session): void
    {
        if ($session === null) {
            return;
        }

        $marks = (array) $session->get(self::SKIPPED_KEY, []);
        unset($marks[$purpose->value]);
        $session->put(self::SKIPPED_KEY, $marks);
    }

    public function wasSkipped(string $phone, PhoneVerificationPurpose $purpose, ?Session $session): bool
    {
        $canonical = PhoneVerificationService::canonical($phone);
        $entry = $session === null ? null : ((array) $session->get(self::SKIPPED_KEY, []))[$purpose->value] ?? null;

        if ($canonical === null || ! is_array($entry) || ! is_int($entry['until'] ?? null) || ! is_string($entry['phone'] ?? null)) {
            return false;
        }

        return $entry['until'] > now()->getTimestamp() && hash_equals($entry['phone'], $this->mark($canonical));
    }

    // ------------------------------------------------------------------ the server check of a request (PV-13 г)

    /**
     * Runs inside the checkout / reservation / inquiry transaction before the request is created.
     *
     * @return bool the value of `orders.phone_verified`: true only when the number of the form is verified
     *
     * @throws SalesException the account is locked, or the number has to be verified and is not (and no skip mark)
     */
    public function assertForRequest(string $phone, ?Customer $customer, ?Session $session, bool $hasOrder, bool $hasReservation, bool $hasInquiry, int $orderPartTotal): bool
    {
        if ($this->accountBlocked($customer)) {
            throw new SalesException(__('phone_verification.errors.account_locked'));
        }

        // The lock was lifted by a skip mark of the profile phone: the mark is used up by this request, but only once
        // the checkout transaction has committed (TD-31): a rollback (deadlock retry, a later failure) keeps it usable.
        if ($customer !== null && $customer->phone_verified_at === null && $this->registrationMode() === PhoneVerificationRegistrationMode::Required) {
            DB::afterCommit(fn () => $this->forgetSkipped(PhoneVerificationPurpose::Registration, $session));
        }

        $purpose = PhoneVerificationPurpose::GuestCheckout;
        $verified = $this->isPhoneVerified($phone, $purpose, $customer, $session);

        if ($verified || ! $this->guestNeedsVerification($customer, $hasOrder, $hasReservation, $hasInquiry, $orderPartTotal)) {
            return $verified;
        }

        if ($this->wasSkipped($phone, $purpose, $session)) {
            DB::afterCommit(fn () => $this->forgetSkipped($purpose, $session));

            return false;
        }

        throw new SalesException(__('phone_verification.errors.required'));
    }

    private function mark(string $phone): string
    {
        return hash_hmac('sha256', 'skip|'.$phone, (string) config('app.key'));
    }
}
