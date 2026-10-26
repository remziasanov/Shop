<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * Outcome of {@see PriceCalculator}: prices in integer kopecks plus the ids of the applied rules
 * (in application order) and an explanation.
 */
final readonly class PriceResult
{
    /**
     * @param  list<int>  $appliedRuleIds
     */
    public function __construct(
        public int $finalPrice,
        public ?int $displayOldPrice,
        public array $appliedRuleIds,
        public PriceExplanation $explanation,
    ) {}

    /** "1290.50" for the decimal(12,2) column. */
    public function finalRubles(): string
    {
        return Money::toRubles($this->finalPrice);
    }

    public function oldRubles(): ?string
    {
        return $this->displayOldPrice !== null ? Money::toRubles($this->displayOldPrice) : null;
    }
}
