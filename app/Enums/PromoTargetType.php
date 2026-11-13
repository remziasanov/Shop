<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of a row in `promo_code_targets` (22.5): a category or product id, or a brand name.
 */
enum PromoTargetType: string
{
    case Category = 'category';
    case Product = 'product';
    case Brand = 'brand';
}
