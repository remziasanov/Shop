<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

/**
 * Answer of a flash call request (PV-02): the provider's request id, the code actually used (the last 4 digits of the
 * caller number — the client's one for a driver that takes it, the provider's one otherwise) and the cost when reported.
 */
final readonly class FlashCallResult
{
    public function __construct(
        public string $requestId,
        public string $code,
        public ?string $cost = null,
    ) {}
}
