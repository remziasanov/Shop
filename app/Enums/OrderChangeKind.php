<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of a row of the order change log (OE-07). Stored as a string in `order_changes.kind`.
 */
enum OrderChangeKind: string
{
    case Field = 'field';
    case ItemAdded = 'item_added';
    case ItemRemoved = 'item_removed';
    case ItemQty = 'item_qty';
    case ItemPrice = 'item_price';
    case Delivery = 'delivery';
    case Discount = 'discount';
    case Stock = 'stock';

    public function label(): string
    {
        return __('order_edit.kinds.'.$this->value);
    }
}
