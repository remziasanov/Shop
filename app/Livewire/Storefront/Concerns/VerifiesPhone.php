<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Concerns;

use App\Enums\PhoneVerificationMethod;
use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\PhoneVerificationException;
use App\Models\Customer;
use App\Services\PhoneVerification\PhoneVerificationGate;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Services\PhoneVerification\PhoneVerificationStart;
use Livewire\Attributes\Locked;

/**
 * The phone verification step of a Livewire form (PV-03, PV-07, PV-13): the form validates first, then
 * {@see beginPhoneVerification()} calls the number and the component shows the code field
 * (`<x-storefront.phone-verify>`); a correct code runs {@see afterPhoneVerified()}, which finishes the original action.
 *
 * The state is `#[Locked]` where a client could gain from changing it. The code itself is checked by the service (HMAC,
 * session binding, attempts, limits); nothing here decides whether a number is verified.
 */
trait VerifiesPhone
{
    /** The code step is shown. */
    #[Locked]
    public bool $pvActive = false;

    /** Canonical number a code was requested for. */
    #[Locked]
    public string $pvPhone = '';

    /** `flash_call` | `sms`: how the last code was sent. */
    #[Locked]
    public string $pvMethod = '';

    /** «Звонок не пришёл? Получить SMS» may be offered (mode «звонок, а SMS — по кнопке»). */
    #[Locked]
    public bool $pvCanSms = false;

    /** Unix time when the next call / the SMS button may be used (the timer of the screen). */
    #[Locked]
    public int $pvResendAt = 0;

    /** The four digits typed by the customer. */
    public string $pvCode = '';

    /** What the verification is for. */
    abstract protected function pvPurpose(): PhoneVerificationPurpose;

    /** The signed-in customer (null for a guest). */
    abstract protected function pvCustomer(): ?Customer;

    /** The original action goes on: the number is verified. */
    abstract protected function afterPhoneVerified(): void;

    /**
     * Calls the number (PV-13 б: only after the rest of the form passed validation). Returns true when the caller may go
     * on at once (the provider failed and the owner chose to skip, PV-05); false when the code step is shown or an error
     * was added.
     */
    protected function beginPhoneVerification(string $phone): bool
    {
        $this->pvCode = '';
        $this->resetErrorBag(['pvCode', 'phone']);

        try {
            $start = app(PhoneVerificationGate::class)->begin($phone, $this->pvPurpose(), PhoneVerificationGate::session(), $this->pvIp(), $this->pvCustomer());
        } catch (PhoneVerificationException $e) {
            $this->pvActive = false;
            $this->addError('phone', $e->getMessage());

            return false;
        }

        if ($start->skipped) {
            $this->pvActive = false;

            return true;
        }

        $this->showStep($start);

        return false;
    }

    /**
     * The customer typed the code (Enter, the «Подтвердить» button or the main button of the form).
     */
    public function confirmPhoneCode(): void
    {
        if (! $this->pvActive) {
            return;
        }

        $this->resetErrorBag('pvCode');

        try {
            app(PhoneVerificationService::class)->verify($this->pvPhone, $this->pvPurpose(), $this->pvCode, PhoneVerificationGate::session(), $this->pvIp());
        } catch (PhoneVerificationException $e) {
            $this->addError('pvCode', $e->getMessage());

            // A code that cannot be entered any more (expired, attempts used up) ends the step: a new call is needed.
            if (in_array($e->reason->value, ['expired', 'too_many_attempts', 'no_active_code'], true)) {
                $this->pvCode = '';
            }

            return;
        }

        $this->pvActive = false;
        $this->pvCode = '';
        $this->afterPhoneVerified();
    }

    /**
     * «Позвонить ещё раз»: a new code, the same limits (PV-06).
     */
    public function resendPhoneCall(): void
    {
        if (! $this->pvActive) {
            return;
        }

        $this->resetErrorBag('pvCode');

        try {
            $start = app(PhoneVerificationGate::class)->begin($this->pvPhone, $this->pvPurpose(), PhoneVerificationGate::session(), $this->pvIp(), $this->pvCustomer());
        } catch (PhoneVerificationException $e) {
            $this->addError('pvCode', $e->getMessage());

            return;
        }

        if ($start->skipped) {
            // The provider failed meanwhile and the owner skips: the form goes on without a verified number.
            $this->pvActive = false;
            $this->afterPhoneVerified();

            return;
        }

        $this->pvCode = '';
        $this->showStep($start);
    }

    /**
     * «Звонок не пришёл? Получить SMS» (PV-04): a separate action, never in the same request as the call.
     */
    public function requestPhoneSms(): void
    {
        if (! $this->pvActive) {
            return;
        }

        $this->resetErrorBag('pvCode');

        try {
            $start = app(PhoneVerificationService::class)->requestSms($this->pvPhone, $this->pvPurpose(), PhoneVerificationGate::session(), $this->pvIp(), $this->pvCustomer());
        } catch (PhoneVerificationException $e) {
            $this->addError('pvCode', $e->getMessage());

            return;
        }

        if ($start->skipped) {
            app(PhoneVerificationGate::class)->markSkipped($start->phone, $this->pvPurpose(), PhoneVerificationGate::session());
            $this->pvActive = false;
            $this->afterPhoneVerified();

            return;
        }

        $this->pvCode = '';
        $this->showStep($start);
    }

    public function cancelPhoneVerification(): void
    {
        $this->pvActive = false;
        $this->pvCode = '';
        $this->resetErrorBag(['pvCode', 'phone']);
    }

    /**
     * PV-13 г: another number than the one verified (or being verified) resets the verification. Call from `updatedPhone()`.
     */
    protected function resetPhoneVerificationOnChange(string $phone): void
    {
        if ($this->pvActive && PhoneVerificationService::canonical($phone) !== $this->pvPhone) {
            $this->cancelPhoneVerification();
        }

        app(PhoneVerificationGate::class)->forgetIfChanged($phone, $this->pvPurpose(), PhoneVerificationGate::session());
    }

    private function showStep(PhoneVerificationStart $start): void
    {
        $this->pvActive = true;
        $this->pvPhone = $start->phone;
        $this->pvMethod = $start->method->value;
        $this->pvCanSms = $start->canRequestSms && $start->method === PhoneVerificationMethod::FlashCall;
        $this->pvResendAt = now()->getTimestamp() + $start->resendAfter;
    }

    private function pvIp(): string
    {
        return (string) request()->ip();
    }
}
