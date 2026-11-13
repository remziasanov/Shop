<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Enums\CartLineKind;
use App\Enums\CheckoutChoice;
use App\Enums\PriceSource;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\TierHint;

/**
 * One cart row prepared for display. Money is in kopecks. `lineTotal` uses the CURRENT price (SF-42). `variant` / `variantLabel`: the variant of the row and its label (VR-12). `currentPrice` is the unit price of the viewer's group for `qty` (GP-11); `tierHint` is «Ещё N шт — и цена станет X ₽», `priceSource` / `tierMinQty` tell where the price comes from. `promoOldPrice` (PM-05) is the unit price before the shop's own promotion: only a price rule's old price or the manual compare-at price, never the struck-through retail price a group may show; null when the row is not on promotion.
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
        public ?int $promoOldPrice = null,
        /** MR-02: how the row can be checked out (null for an unavailable row). */
        public ?CartLineKind $kind = null,
        /** MR-04: the part the row goes to for the choice the summary was built with (null for an unavailable row). */
        public ?CheckoutChoice $target = null,
        /** MR-07: the quantity a reservation of the row can hold now (null = stock not tracked). */
        public ?int $maxReserveQty = null,
    ) {}
}
