<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Product;
use App\Settings\SalesSettings;
use App\Support\QtyLimit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cart merge on sign-in (CA-15). The guest cart (the `cart` cookie) joins the cart of the customer
 * (`carts.customer_id`, unique): quantities of the same product are summed and limited by the available stock, other
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

        $ownItems = CartItem::query()->where('cart_id', $own->id)->get()->keyBy('product_id');
        $guestItems = CartItem::query()->where('cart_id', $guest->id)->orderBy('id')->get();
        $products = Product::query()->whereIn('id', $guestItems->pluck('product_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($guestItems as $item) {
            $product = $products->get($item->product_id);
            $existing = $ownItems->get($item->product_id);

            if ($product === null) {
                continue;
            }

            $total = min(($existing->qty ?? 0) + $item->qty, QtyLimit::MAX);
            $cap = $this->stockCap($product);

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
                    'qty' => $total,
                    'price_at_add' => $item->price_at_add,
                ]);
            }
        }

        $own->touch();
        Cart::query()->whereKey($guest->id)->delete();
    }

    /**
     * The most that can be in a cart, or null when there is no limit.
     */
    private function stockCap(Product $product): ?int
    {
        if (! $this->settings->stock_tracking || $this->settings->allow_backorder) {
            return null;
        }

        $available = $product->availableQty();

        return $available === null ? null : max($available, 0);
    }
}
