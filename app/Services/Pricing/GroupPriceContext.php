<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\CustomerGroup;
use App\Settings\PricingSettings;

/**
 * The customer group a price is calculated for (GP-05): which rules take part, the group discount and whether the
 * rounding step applies to the derived price (GP-19). Passed to {@see PriceCalculator}; without it the retail price is
 * calculated.
 */
final readonly class GroupPriceContext
{
    /**
     * @param  string  $discountPercent  decimal string, 0–99.99
     */
    public function __construct(
        public int $groupId,
        public string $discountPercent = '0',
        public bool $applyRounding = true,
    ) {}

    public static function for(CustomerGroup $group, PricingSettings $settings): self
    {
        return new self($group->id, (string) $group->default_discount_percent, $settings->group_prices_rounding);
    }

    public function hasDiscount(): bool
    {
        return bccomp($this->discountPercent, '0', 2) > 0;
    }
}
