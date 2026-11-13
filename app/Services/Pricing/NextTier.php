<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * The next quantity tier of a customer group above the current quantity (GP-03): from `minQty` pieces the unit price
 * is `price` kopecks.
 */
final readonly class NextTier
{
    public function __construct(public int $minQty, public int $price) {}
}
