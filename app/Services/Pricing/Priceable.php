<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * A sellable price carrier for {@see PriceResolver} (GP-03): a product without variants or a variant. A card with
 * variants may be resolved too, but only as a listing price (its card-level row, `variant_key` 0, carries the minimum).
 */
interface Priceable
{
    public function priceProductId(): int;

    /**
     * Null for a product, the variant id for a variant.
     */
    public function priceVariantId(): ?int;

    /**
     * Retail `final_price` in kopecks (rules for everyone applied, no group).
     */
    public function retailPriceKopecks(): int;

    /**
     * Retail `display_old_price` in kopecks.
     */
    public function retailOldPriceKopecks(): ?int;
}
