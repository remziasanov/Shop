<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\RoundingDirection;
use App\Enums\RoundingStep;

/**
 * Price rounding (D-024): to a step of whole rubles, up / to nearest (half up) / down, optionally
 * followed by the "psychological" -1 RUB (only for steps of 10 RUB and more, and only for a positive result).
 */
final class Rounding
{
    /**
     * @param  int  $kopecks  non-negative price
     */
    public function apply(int $kopecks, RoundingStep $step, RoundingDirection $direction, bool $psychological = false): int
    {
        if ($step === RoundingStep::None) {
            return $kopecks;
        }

        $unit = $step->value * 100;
        $remainder = $kopecks % $unit;
        $floor = $kopecks - $remainder;

        $rounded = match ($direction) {
            RoundingDirection::Down => $floor,
            RoundingDirection::Up => $remainder === 0 ? $floor : $floor + $unit,
            RoundingDirection::Nearest => $remainder * 2 >= $unit ? $floor + $unit : $floor,
        };

        if ($psychological && $step->value >= 10 && $rounded > 0) {
            $rounded -= 100;
        }

        return $rounded;
    }
}
