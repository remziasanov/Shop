<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the effective sales mode of a product comes from (D-016).
 */
enum SalesModeSource: string
{
    case Product = 'product';
    case Category = 'category';
    case Shop = 'shop';
    case PriceOnRequest = 'price_on_request';
    case Group = 'group';

    public function label(): string
    {
        return __('catalog.enums.sales_mode_source.'.$this->value);
    }
}
