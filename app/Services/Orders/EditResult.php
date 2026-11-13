<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;

/**
 * Outcome of {@see OrderEditor::apply()}: the order as saved and whether anything changed (saving the same data again
 * changes nothing and does not move the version).
 */
final readonly class EditResult
{
    public function __construct(
        public Order $order,
        public bool $changed,
        public ?string $batchUuid,
        public int $oldTotal,
        public int $newTotal,
    ) {}
}
