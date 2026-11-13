<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which items a promo code applies to (PM-03).
 */
enum PromoScope: string
{
    case All = 'all';
    case Categories = 'categories';
    case Products = 'products';
    case Brands = 'brands';

    public function label(): string
    {
        return __('promo.scopes.'.$this->value);
    }

    /**
     * The target type stored in `promo_code_targets` for this scope; null for «all».
     */
    public function targetType(): ?PromoTargetType
    {
        return match ($this) {
            self::All => null,
            self::Categories => PromoTargetType::Category,
            self::Products => PromoTargetType::Product,
            self::Brands => PromoTargetType::Brand,
        };
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
