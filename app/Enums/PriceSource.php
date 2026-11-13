<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the price of a customer group came from (GP-03, GP-12), in the order of 21.2.
 */
enum PriceSource: string
{
    case Tier = 'tier';
    case GroupPrice = 'group_price';
    case GroupDiscount = 'group_discount';
    case Retail = 'retail';

    public function label(): string
    {
        return __('pricing.price_sources.'.$this->value);
    }
}
