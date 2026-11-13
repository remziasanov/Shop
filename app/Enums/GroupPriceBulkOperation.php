<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operations of the bulk action «Цены группы» (GP-18).
 */
enum GroupPriceBulkOperation: string
{
    case RetailMinusPercent = 'retail_minus_percent';
    case CostPlusPercent = 'cost_plus_percent';
    case Delete = 'delete';

    public function needsValue(): bool
    {
        return $this !== self::Delete;
    }

    public function label(): string
    {
        return __('pricing.group_bulk.operations.'.$this->value);
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
