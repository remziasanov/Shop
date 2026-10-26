<?php

declare(strict_types=1);

namespace App\Services\Pricing;

enum PriceStepType: string
{
    case Base = 'base';
    case Rule = 'rule';
    case Skipped = 'skipped';
    case Rounding = 'rounding';
    case Fixed = 'fixed';
    case Floor = 'floor';
}
