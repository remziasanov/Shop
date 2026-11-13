<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * «Ещё N шт — и цена станет X ₽ за шт» (GP-11): `missingQty` more pieces reach the tier of `minQty` pieces at `price` kopecks.
 */
final readonly class TierHint
{
    public function __construct(public int $missingQty, public int $minQty, public int $price) {}
}
