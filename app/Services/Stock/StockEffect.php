<?php

declare(strict_types=1);

namespace App\Services\Stock;

/**
 * What an edit does to the stock of one line (OE-04, OE-10). `stockDelta` is the change of `stock_qty` (an order),
 * `reservedDelta` the change of `reserved_qty` (a reservation). `appliedQty` is the new `stock_applied_qty` of the line;
 * `newQty - appliedQty` is the part «под заказ». A line the edit does not count (stock tracking off for the request, a
 * unit without stock) has zero deltas and `tracked` false. `shortage` is the available quantity when the edit cannot be covered, else null.
 */
final readonly class StockEffect
{
    public function __construct(
        public int|string $key,
        public string $name,
        public int $appliedQty,
        public int $stockDelta = 0,
        public int $reservedDelta = 0,
        public ?int $shortage = null,
        public bool $tracked = false,
    ) {}

    public function changesStock(): bool
    {
        return $this->stockDelta !== 0 || $this->reservedDelta !== 0;
    }
}
