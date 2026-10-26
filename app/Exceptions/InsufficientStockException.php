<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;

final class InsufficientStockException extends SalesException
{
    public function __construct(public readonly Product $product, public readonly int $available)
    {
        parent::__construct(__('sales.errors.insufficient_stock', ['name' => $product->name, 'available' => $available]));
    }
}
