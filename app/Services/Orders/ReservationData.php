<?php

declare(strict_types=1);

namespace App\Services\Orders;

use Carbon\CarbonInterface;

/**
 * Reservation form (SF-50). `desiredDate` must not be later than the reservation term.
 */
final readonly class ReservationData
{
    public function __construct(
        public int $qty,
        public string $name,
        public string $phone,
        public ?string $email,
        public ?CarbonInterface $desiredDate,
        public ?string $comment,
        public bool $consent,
        public string $ip,
        public ?string $userAgent = null,
    ) {}
}
