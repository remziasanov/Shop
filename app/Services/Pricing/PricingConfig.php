<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Settings\PricingSettings;

/**
 * Rounding configuration handed to the pure {@see PriceCalculator} (a snapshot of {@see PricingSettings}).
 */
final readonly class PricingConfig
{
    public function __construct(
        public RoundingStep $step = RoundingStep::None,
        public RoundingDirection $direction = RoundingDirection::Up,
        public bool $psychological = false,
        public RoundingScope $scope = RoundingScope::Changed,
    ) {}

    public static function fromSettings(PricingSettings $settings): self
    {
        return new self(
            $settings->rounding_step,
            $settings->rounding_direction,
            $settings->psychological_price,
            $settings->rounding_scope,
        );
    }
}
