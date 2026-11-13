<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Enums\PriceSource;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\TierHint;

/**
 * One cart row prepared for display. Money is in kopecks. `lineTotal` uses the CURRENT price (SF-42). `variant` / `variantLabel`: the variant of the row and its label (VR-12). `currentPrice` is the unit price of the viewer's group for `qty` (GP-11); `tierHint` is «Ещё N шт — и цена станет X ₽», `priceSource` / `tierMinQty` tell where the price comes from.
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
        public ?ProductVariant $variant = null,
        public ?string $variantLabel = null,
        public ?TierHint $tierHint = null,
        public ?PriceSource $priceSource = null,
        public ?int $tierMinQty = null,
    ) {}
}
