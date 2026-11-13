<?php

declare(strict_types=1);

namespace App\Services\Orders;

/**
 * Inquiry form (SF-60).
 */
final readonly class InquiryData
{
    public function __construct(
        public int $qty,
        public string $name,
        public string $phone,
        public ?string $email,
        public ?string $comment,
        public bool $consent,
        public string $ip,
        public ?string $userAgent = null,
        /** PM-24: the promo link of the `shop_pl` cookie (last click). */
        public ?int $promoLinkId = null,
    ) {}
}
