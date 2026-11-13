<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\OrderItem;

/**
 * One line of a stock operation (19.5): a product or one of its variants and a quantity.
 */
final readonly class StockLine
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public int $qty,
    ) {}

    public static function fromItem(OrderItem $item): self
    {
        return new self((int) $item->product_id, $item->variant_id === null ? null : (int) $item->variant_id, (int) $item->qty);
    }
}
