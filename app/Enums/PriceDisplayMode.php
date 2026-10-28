<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Price display of a customer group (CG-06): shown, replaced by «Войдите, чтобы увидеть цену», or «Цена по запросу».
 */
enum PriceDisplayMode: string
{
    case Show = 'show';
    case Login = 'login';
    case Request = 'request';

    public function hidesPrices(): bool
    {
        return $this !== self::Show;
    }

    public function label(): string
    {
        return __('customer_groups.enums.price_display.'.$this->value);
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
