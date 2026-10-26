<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Models\Product;

/**
 * One cart row prepared for display. Money is in kopecks. `lineTotal` uses the CURRENT price (SF-42).
 */
final readonly class CartLine
{
    public function __construct(
        public CartItem $item,
        public ?Product $product,
        public int $qty,
        public int $priceAtAdd,
        public int $currentPrice,
        public int $lineTotal,
        public bool $priceChanged,
        public bool $unavailable,
        public ?int $maxQty,
        public ?string $issue,
    ) {}
}
