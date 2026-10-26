<?php

declare(strict_types=1);

namespace App\Services\Sales;

final readonly class PaymentOption
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
    ) {}
}
