<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Storefront sections a customer group can see (CG-04).
 */
enum StorefrontSection: string
{
    case Catalog = 'catalog';
    case Search = 'search';
    case Featured = 'featured';
    case Promotions = 'promotions';
    case Brands = 'brands';
    case PriceList = 'price_list';

    public function label(): string
    {
        return __('customer_groups.enums.section.'.$this->value);
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

    /**
     * Sections that exist in v1.0 and stay visible by default (the wholesale price list does not).
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [self::Catalog->value, self::Search->value, self::Featured->value, self::Promotions->value, self::Brands->value];
    }
}
