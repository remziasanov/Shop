<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\Product;

/**
 * The unit whose stock is counted (VR-06): a product without variants or one variant. Both models carry
 * `stock_qty` (null = not tracked) and `reserved_qty`; only {@see StockService} writes them.
 */
interface Stockable
{
    /**
     * The product card: the product itself, or the card a variant belongs to (sales mode, category and visibility
     * always come from the card).
     */
    public function stockProduct(): Product;

    /**
     * Id of the card (no query).
     */
    public function stockProductId(): int;

    /**
     * The variant id, or null for a product.
     */
    public function stockVariantId(): ?int;

    /**
     * stock_qty - reserved_qty, or null when stock is not tracked. May be negative: callers clamp.
     */
    public function availableQty(): ?int;

    /**
     * Name for messages: the product name, with the variant label for a variant.
     */
    public function stockName(): string;
}
