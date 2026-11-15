<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

/**
 * Answer of an SMS request (PV-02): the provider's request id and the cost (decimal string) when reported.
 */
final readonly class SmsResult
{
    public function __construct(
        public string $requestId,
        public ?string $cost = null,
    ) {}
}
