<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\PhoneVerificationMethod;
use App\Models\PhoneVerification;
use Carbon\CarbonInterface;

/**
 * Result of {@see PhoneVerificationService::start()} / `requestSms()`.
 *
 * `skipped` = the provider failed and the owner chose «пропускать подтверждение» (PV-05): the caller goes on with the
 * request WITHOUT a verified number (`orders.phone_verified = false`). Otherwise a call or an SMS was sent and the
 * customer has to enter the code.
 */
final readonly class PhoneVerificationStart
{
    public function __construct(
        public bool $skipped,
        public string $phone,
        public PhoneVerificationMethod $method,
        public PhoneVerification $verification,
        /** Seconds before another call / the SMS button may be offered (PV-04). */
        public int $resendAfter,
        /** The «Получить SMS» button is offered after `resendAfter` seconds (mode «звонок, а SMS — по кнопке»). */
        public bool $canRequestSms,
        public ?CarbonInterface $expiresAt,
    ) {}
}
