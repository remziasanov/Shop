<?php

declare(strict_types=1);

namespace App\Services\Sales;

/**
 * A delivery / pickup method from the sales settings. Money is in kopecks (D-009).
 */
final readonly class DeliveryOption
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public int $price,
        public ?int $freeFrom,
        public bool $requiresAddress,
    ) {}

    /**
     * Delivery price for the given items total: free from the "free from" amount (SF-45).
     */
    public function priceFor(int $itemsTotal): int
    {
        if ($this->freeFrom !== null && $itemsTotal >= $this->freeFrom) {
            return 0;
        }

        return $this->price;
    }
}
