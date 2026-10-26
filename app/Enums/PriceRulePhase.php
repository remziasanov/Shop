<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceRulePhase: string
{
    case Markup = 'markup';
    case Discount = 'discount';
}
