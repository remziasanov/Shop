<?php

declare(strict_types=1);

namespace App\Enums;

enum StockDisplay: string
{
    case Exact = 'exact';
    case Presence = 'presence';
    case Low = 'low';

    public function label(): string
    {
        return __('shop.enums.stock_display.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }
}
