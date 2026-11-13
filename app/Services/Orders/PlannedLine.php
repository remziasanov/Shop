<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\PriceSource;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Stock\StockEffect;

/**
 * A position of an order after the edit, together with what it was (`item`, null for an added one). Money is kopecks.
 */
final class PlannedLine
{
    /** Filled by the editor once the stock plan is known. */
    public ?StockEffect $effect = null;

    /** This line's share of the promo discount (kopecks). */
    public int $promoDiscount = 0;

    public function __construct(
        public readonly ?OrderItem $item,
        public readonly ?Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $qty,
        public readonly int $price,
        public readonly ?PriceSource $source,
        public readonly ?int $tierMinQty,
        public readonly ?int $retailPrice,
        public readonly int $oldQty,
        public readonly ?int $oldPrice,
        public readonly string $name,
        public readonly string $label,
    ) {}

    public function isNew(): bool
    {
        return $this->item === null;
    }

    public function total(): int
    {
        return $this->price * $this->qty;
    }

    public function priceChanged(): bool
    {
        return $this->oldPrice !== null && $this->oldPrice !== $this->price;
    }
}
