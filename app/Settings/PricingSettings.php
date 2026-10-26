<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use Spatie\LaravelSettings\Settings;

class PricingSettings extends Settings
{
    public RoundingStep $rounding_step;

    public RoundingDirection $rounding_direction;

    /** Subtract 1 RUB from the rounded price (e.g. 1 000 -> 999). */
    public bool $psychological_price;

    public RoundingScope $rounding_scope;

    public bool $show_old_price;

    public bool $show_discount_percent;

    public static function group(): string
    {
        return 'pricing';
    }
}
