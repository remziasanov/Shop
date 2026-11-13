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

    /** GP-19: apply the rounding step to prices derived for customer groups (discount, group rules). */
    public bool $group_prices_rounding;

    /** GP-11, GP-19: show «Ещё N шт — и цена станет X» in the cart. */
    public bool $tier_hint_enabled;

    /** GP-11: the next tier is hinted when the missing quantity is at most this percent of the current quantity... */
    public int $tier_hint_percent;

    /** ...or at most this many pieces. */
    public int $tier_hint_qty;

    public static function group(): string
    {
        return 'pricing';
    }
}
