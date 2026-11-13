<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Outcome of {@see PriceTierService::replace()}.
 */
final readonly class TierSaveResult
{
    /**
     * @param  list<TierIssue>  $warnings
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public array $warnings = [],
    ) {}

    public function changed(): bool
    {
        return $this->created + $this->updated + $this->deleted > 0;
    }
}
