<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceRuleTargetType: string
{
    case Category = 'category';
    case Product = 'product';
    case Brand = 'brand';
    case Flag = 'flag';

    public function label(): string
    {
        return __('pricing.target_types.'.$this->value);
    }
}
