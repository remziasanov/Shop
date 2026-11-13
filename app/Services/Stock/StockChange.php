<?php

declare(strict_types=1);

namespace App\Services\Stock;

/**
 * One line of an order edit as {@see StockService::applyItemChanges()} sees it (OE-04): the stock unit, the quantity
 * the line had (`oldQty`, 0 for an added line), the quantity it will have (`newQty`, 0 for a removed line) and the part
 * of the old quantity that was really deducted / held (`appliedQty`, 0 for an added line).
 */
final readonly class StockChange
{
    public function __construct(
        public int|string $key,
        public int $productId,
        public ?int $variantId,
        public int $oldQty,
        public int $newQty,
        public int $appliedQty,
    ) {}
}
