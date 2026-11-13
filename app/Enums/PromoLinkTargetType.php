<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Destination of a promo link (PM-21).
 */
enum PromoLinkTargetType: string
{
    case Product = 'product';
    case Variant = 'variant';
    case Category = 'category';
    case CatalogFilter = 'catalog_filter';
    case Url = 'url';

    public function label(): string
    {
        return __('promo.link_targets.'.$this->value);
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
