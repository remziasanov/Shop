<?php

declare(strict_types=1);

namespace App\Services\Variants;

/**
 * One planned combination of the generator (VR-05).
 */
final readonly class CombinationRow
{
    /**
     * @param  list<int>  $valueIds  one option value per axis, in axis order
     * @param  bool  $exists  a variant with this combination already exists
     * @param  bool  $skuTaken  the proposed SKU is used by a product or variant, or twice in the plan
     */
    public function __construct(
        public array $valueIds,
        public string $label,
        public string $sku,
        public bool $exists,
        public bool $skuTaken = false,
    ) {}
}
