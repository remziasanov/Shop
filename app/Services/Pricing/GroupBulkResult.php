<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Outcome of {@see GroupPriceBulkChanger::apply()}, counted in priced items (a product without variants or a variant).
 * `skipped` maps a reason code (`no_cost`, `invalid_price`, `tier_conflict`) to the number of items.
 */
final readonly class GroupBulkResult
{
    /**
     * @param  array<string, int>  $skipped
     */
    public function __construct(public int $changed = 0, public int $unchanged = 0, public array $skipped = []) {}

    public function skippedTotal(): int
    {
        return array_sum($this->skipped);
    }

    public function merge(self $other): self
    {
        $skipped = $this->skipped;

        foreach ($other->skipped as $reason => $count) {
            $skipped[$reason] = ($skipped[$reason] ?? 0) + $count;
        }

        return new self($this->changed + $other->changed, $this->unchanged + $other->unchanged, $skipped);
    }
}
