<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stock display of a customer group (CG-27). `Inherit` follows the global sales setting ({@see StockDisplay}).
 */
enum GroupStockDisplay: string
{
    case Inherit = 'inherit';
    case Exact = 'exact';
    case YesNo = 'yes_no';
    case Low = 'low';

    /**
     * The global display mode this group value stands for; `$global` is used for `Inherit`.
     */
    public function resolve(StockDisplay $global): StockDisplay
    {
        return match ($this) {
            self::Inherit => $global,
            self::Exact => StockDisplay::Exact,
            self::YesNo => StockDisplay::Presence,
            self::Low => StockDisplay::Low,
        };
    }

    public function label(): string
    {
        return __('customer_groups.enums.stock_display.'.$this->value);
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
