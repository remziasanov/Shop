<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceRuleAction: string
{
    case MarkupPercent = 'markup_percent';
    case MarkupFixed = 'markup_fixed';
    case CostMarkupPercent = 'cost_markup_percent';
    case DiscountPercent = 'discount_percent';
    case DiscountFixed = 'discount_fixed';

    /** Markups are applied first, then discounts (PR-01). */
    public function phase(): PriceRulePhase
    {
        return match ($this) {
            self::MarkupPercent, self::MarkupFixed, self::CostMarkupPercent => PriceRulePhase::Markup,
            self::DiscountPercent, self::DiscountFixed => PriceRulePhase::Discount,
        };
    }

    /** The value is a percentage (otherwise rubles). */
    public function isPercent(): bool
    {
        return match ($this) {
            self::MarkupPercent, self::CostMarkupPercent, self::DiscountPercent => true,
            self::MarkupFixed, self::DiscountFixed => false,
        };
    }

    public function isDiscount(): bool
    {
        return $this->phase() === PriceRulePhase::Discount;
    }

    public function label(): string
    {
        return __('pricing.actions.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
