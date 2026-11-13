<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\CheckoutChoice;
use App\Models\Product;
use App\Services\Cart\CartLine;
use App\Services\Pricing\ResolvedPrice;

/**
 * One purchasable cart row as {@see PromoService::evaluate()} sees it. Money is integer kopecks. `unitPrice` is the
 * price after group / tier prices and promotions; `promoOldPrice` the price before the shop's own promotion (PM-05),
 * null when the row is not on promotion. `key` identifies the row in {@see PromoResult::$lineDiscounts}.
 */
final readonly class PromoLine
{
    public function __construct(
        public int|string $key,
        public int $productId,
        public ?int $variantId,
        public ?int $categoryId,
        public ?string $brand,
        public int $qty,
        public int $unitPrice,
        public ?int $promoOldPrice = null,
    ) {}

    /**
     * The row of a cart page line (the key is the cart item id); null for a row that cannot be bought.
     */
    public static function fromCartLine(CartLine $line): ?self
    {
        // MR-08: a row that goes to the reservation takes no promo code.
        if ($line->unavailable || $line->product === null || $line->target === CheckoutChoice::Reservation) {
            return null;
        }

        return self::make($line->item->id, $line->product, $line->variant?->id, $line->qty, $line->currentPrice, $line->promoOldPrice);
    }

    public static function make(int|string $key, Product $product, ?int $variantId, int $qty, int $unitPrice, ?int $oldPrice): self
    {
        return new self($key, $product->id, $variantId, $product->category_id, $product->brand, $qty, $unitPrice, $oldPrice);
    }

    /**
     * PM-05: the old price of a resolved price when it is above the unit price. The resolver only fills it from the
     * shop's own rules / compare-at price, never from the group's struck-through retail price.
     */
    public static function oldPriceOf(ResolvedPrice $price): ?int
    {
        return $price->oldPrice !== null && $price->oldPrice > $price->unitPrice ? $price->oldPrice : null;
    }

    public function regularTotal(): int
    {
        return $this->unitPrice * $this->qty;
    }
}
