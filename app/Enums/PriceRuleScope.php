<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceRuleScope: string
{
    case All = 'all';
    case Categories = 'categories';
    case Products = 'products';
    case Brands = 'brands';
    case Flags = 'flags';

    public function label(): string
    {
        return __('pricing.scopes.'.$this->value);
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
