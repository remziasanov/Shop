<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;

/**
 * A phone verification provider (PV-02). One HTTP request per call, no retries (connect <= 3 s, answer <= 5 s).
 * Implementations throw {@see PhoneProviderFailure} only for the PV-05 failures (network, timeout, 5xx, authorization)
 * and {@see PhoneProviderRefusal} when the provider answered but refused (zero balance, refused number).
 * Messages of both never contain credentials.
 */
interface PhoneVerificationGateway
{
    /**
     * Flash call to `$phone` (+7XXXXXXXXXX). A driver that accepts the code from the client (SMS Aero) uses `$code`;
     * a driver that generates it (callcheck of SMS.ru) ignores the argument and returns its own code.
     *
     * @param  string|null  $code  4 digits, null when {@see supportsCallerCode()} is false
     *
     * @throws PhoneProviderFailure
     * @throws PhoneProviderRefusal
     */
    public function startFlashCall(string $phone, ?string $code): FlashCallResult;

    /**
     * @throws PhoneProviderFailure
     * @throws PhoneProviderRefusal
     */
    public function sendSms(string $phone, string $text): SmsResult;

    /**
     * Balance in roubles as a decimal string, null when the driver cannot tell.
     *
     * @throws PhoneProviderFailure
     */
    public function balance(): ?string;

    /**
     * True when the client's code is passed to the provider (the service then generates the code).
     */
    public function supportsCallerCode(): bool;
}
