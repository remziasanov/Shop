<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Product;
use App\Services\Stock\Stockable;

final class InsufficientStockException extends SalesException
{
    /** The product card (for a variant: the card the variant belongs to). */
    public readonly Product $product;

    public function __construct(public readonly Stockable $stockable, public readonly int $available)
    {
        $this->product = $stockable->stockProduct();

        parent::__construct(__('sales.errors.insufficient_stock', ['name' => $stockable->stockName(), 'available' => $available]));
    }
}
