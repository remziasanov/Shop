<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Storefront sort orders (SF-12).
 */
enum CatalogSort: string
{
    case Default = 'default';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case Name = 'name';
    case New = 'new';

    public function label(): string
    {
        return __('storefront.sort.'.$this->value);
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
