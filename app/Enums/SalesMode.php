<?php

declare(strict_types=1);

namespace App\Enums;

enum SalesMode: string
{
    case Cart = 'cart';
    case Reservation = 'reservation';
    case Both = 'both';
    case Inquiry = 'inquiry';
    case Showcase = 'showcase';

    public function label(): string
    {
        return __('shop.enums.sales_mode.'.$this->value);
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
