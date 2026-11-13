<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\OrderItem;

/**
 * One position of the order editor form (OE-02). An existing position is addressed by `itemId` (its product and
 * variant are those of the stored row); a new one by `productId` / `variantId`. A position that matches a stored one by
 * product and variant is merged into it (OE-13). `price` is in kopecks: null leaves the stored price (an added position
 * gets the price list price), a different value is a manual price (needs `orders.edit_prices`); `recalculate` asks for the
 * price list price of the new quantity (OE-03, also needs `orders.edit_prices`).
 */
final readonly class EditItem
{
    public function __construct(
        public ?int $itemId,
        public ?int $productId,
        public ?int $variantId,
        public int $qty,
        public ?int $price = null,
        public bool $recalculate = false,
    ) {}

    /**
     * The position exactly as stored (an unchanged row of the form).
     */
    public static function fromItem(OrderItem $item, ?int $qty = null): self
    {
        return new self($item->id, $item->product_id, $item->variant_id, $qty ?? $item->qty, null);
    }
}
