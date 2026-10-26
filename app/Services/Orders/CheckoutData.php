<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Services\Sales\CheckoutMethods;

/**
 * Validated checkout form (SF-44). `deliveryKey` / `paymentKey` are keys from {@see CheckoutMethods}.
 */
final readonly class CheckoutData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email,
        public string $deliveryKey,
        public ?string $address,
        public string $paymentKey,
        public ?string $comment,
        public bool $consent,
        public string $ip,
        public ?string $userAgent = null,
    ) {}
}
