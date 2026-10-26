<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operations of the bulk base price change (AD-42): ±X % or ±N ₽.
 */
enum BulkPriceOperation: string
{
    case IncreasePercent = 'increase_percent';
    case DecreasePercent = 'decrease_percent';
    case IncreaseFixed = 'increase_fixed';
    case DecreaseFixed = 'decrease_fixed';

    public function isPercent(): bool
    {
        return $this === self::IncreasePercent || $this === self::DecreasePercent;
    }

    public function isIncrease(): bool
    {
        return $this === self::IncreasePercent || $this === self::IncreaseFixed;
    }

    public function label(): string
    {
        return __('admin_pricing.bulk.operations.'.$this->value);
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
