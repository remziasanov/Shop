<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of a promo code discount (PM-02).
 */
enum PromoType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case FreeDelivery = 'free_delivery';

    public function label(): string
    {
        return __('promo.types.'.$this->value);
    }

    /**
     * Whether the code carries a value (percent or rubles).
     */
    public function hasValue(): bool
    {
        return $this !== self::FreeDelivery;
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
