<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Stock\Stockable;
use App\Settings\SalesSettings;
use App\Support\QtyLimit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cart merge on sign-in (CA-15). The guest cart (the `cart` cookie) joins the cart of the customer
 * (`carts.customer_id`, unique): quantities of the same product (and variant) are summed and limited by the available stock, other
 * products are moved over. The guest cart is deleted afterwards, so the customer cart is the only one and follows the
 * customer to other devices.
 *
 * The stock limit is the one of the cart ({@see CartService::add()}): none when stock is not tracked or back orders
 * are allowed. A product that cannot be added at all keeps the quantity it already had in the customer cart.
 */
final class CartMerger
{
    public function __construct(private readonly SalesSettings $settings) {}

    /**
     * @param  string|null  $guestToken  the value of the cart cookie (anything but a UUID is ignored)
     */
    public function merge(Customer $customer, ?string $guestToken): void
    {
        if ($guestToken === null || ! Str::isUuid($guestToken)) {
            return;
        }

        try {
            DB::transaction(fn () => $this->mergeInTransaction($customer, $guestToken));
        } catch (UniqueConstraintViolationException) {
            // A parallel sign-in created the customer cart first: merge into that one.
            DB::transaction(fn () => $this->mergeInTransaction($customer, $guestToken));
        }
    }

    private function mergeInTransaction(Customer $customer, string $guestToken): void
    {
        $guest = Cart::query()->where('token', $guestToken)->whereNull('customer_id')->lockForUpdate()->first();

        if ($guest === null) {
            return;
        }

        $own = Cart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();

        if ($own === null) {
            // The simplest case: the guest cart becomes the customer cart as it is.
            $guest->customer_id = $customer->id;
            $guest->save();

            return;
        }

        // A row is a product or one of its variants: both parts form the key (VR-12).
        $ownItems = CartItem::query()->where('cart_id', $own->id)->get()->keyBy(fn (CartItem $item): string => $item->product_id.':'.$item->variant_key);
        $guestItems = CartItem::query()->where('cart_id', $guest->id)->orderBy('id')->get();
        // Lock order: products, then variants, by ascending id (as in StockService).
        $products = Product::query()->whereIn('id', $guestItems->pluck('product_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variantIds = $guestItems->pluck('variant_id')->filter()->all();
        $variants = $variantIds === [] ? collect() : ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($guestItems as $item) {
            $product = $products->get($item->product_id);
            $variant = $item->variant_id !== null ? $variants->get($item->variant_id) : null;

            if ($product === null || ($item->variant_id !== null && ($variant === null || ! $variant->is_active || (int) $variant->product_id !== (int) $product->id))) {
                continue;
            }

            $existing = $ownItems->get($item->product_id.':'.$item->variant_key);
            $total = min(($existing->qty ?? 0) + $item->qty, QtyLimit::MAX);
            $cap = $this->stockCap($variant ?? $product);

            if ($cap !== null) {
                $total = min($total, $cap);
            }

            if ($existing !== null) {
                // Never below what the customer already had.
                $existing->qty = max($total, $existing->qty);
                $existing->save();
            } elseif ($total >= 1) {
                CartItem::query()->create([
                    'cart_id' => $own->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'qty' => $total,
                    'price_at_add' => $item->price_at_add,
                ]);
            }
        }

        // PM-07, 22.7: the promo code of the guest cart survives the merge unless the customer cart has its own.
        if (($own->promo_code === null || $own->promo_code === '') && $guest->promo_code !== null && $guest->promo_code !== '') {
            $own->promo_code = $guest->promo_code;
            $own->promo_applied_manually = $guest->promo_applied_manually;
        }

        $own->touch();
        Cart::query()->whereKey($guest->id)->delete();
    }

    /**
     * The most that can be in a cart, or null when there is no limit.
     */
    private function stockCap(Stockable $stockable): ?int
    {
        if (! $this->settings->stock_tracking || $this->settings->allow_backorder) {
            return null;
        }

        $available = $stockable->availableQty();

        return $available === null ? null : max($available, 0);
    }
}
