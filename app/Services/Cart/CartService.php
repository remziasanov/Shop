<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\SalesException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\CheckoutMethods;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest cart (SF-40…43, D-047): identified by the `cart` cookie (UUID, 30 days), created lazily on the first add.
 * Every add / update re-checks the sales mode, visibility and stock on the server (SF-41).
 */
final class CartService
{
    public const COOKIE = 'cart';

    public const COOKIE_MINUTES = 60 * 24 * 30;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly CheckoutMethods $methods,
        private readonly CategoryTree $tree,
        private readonly SalesSettings $settings,
    ) {}

    /**
     * The visitor's cart or null when they have none yet.
     */
    public function current(Request $request): ?Cart
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || ! Str::isUuid($token)) {
            return null;
        }

        return Cart::query()->where('token', $token)->first();
    }

    /**
     * The visitor's cart, created on demand; queues the cookie (refreshing its 30 days) on the response.
     */
    public function getOrCreate(Request $request): Cart
    {
        $cart = $this->current($request) ?? Cart::query()->create(['token' => (string) Str::uuid()]);

        Cookie::queue(Cookie::make(
            self::COOKIE,
            $cart->token,
            self::COOKIE_MINUTES,
            '/',
            null,
            app()->isProduction(),
            true,
            false,
            'lax',
        ));

        return $cart;
    }

    /**
     * Adds a product (or increases its quantity). The quantity is limited to the available stock (when the
     * product is tracked and «под заказ» is off); the original `price_at_add` of an existing row is kept so that
     * a price change stays visible.
     *
     * @throws SalesException
     */
    public function add(Cart $cart, Product $product, int $qty = 1): CartItem
    {
        if ($qty < 1) {
            throw new SalesException(__('sales.cart.qty_invalid'));
        }

        return DB::transaction(function () use ($cart, $product, $qty): CartItem {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $actions = $locked !== null && $this->isVisible($locked) ? $this->availability->actionsFor($locked) : null;

            if ($locked === null || $actions === null || ! $actions->canAddToCart) {
                throw new SalesException(__($actions !== null && $actions->outOfStock ? 'sales.cart.out_of_stock' : 'sales.cart.not_available', ['name' => $locked->name ?? $product->name]));
            }

            $item = CartItem::query()->where('cart_id', $cart->id)->where('product_id', $locked->id)->first();
            $total = $qty + ($item->qty ?? 0);

            if ($actions->maxQty !== null) {
                $total = min($total, $actions->maxQty);
            }

            if ($item === null) {
                $item = CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $locked->id,
                    'qty' => $total,
                    'price_at_add' => $locked->final_price,
                ]);
            } else {
                $item->qty = $total;
                $item->save();
            }

            $cart->touch();

            return $item;
        });
    }

    /**
     * Sets the quantity of a row; zero or less removes it. Limited by the available stock.
     *
     * @throws SalesException
     */
    public function update(Cart $cart, int $productId, int $qty): ?CartItem
    {
        if ($qty < 1) {
            $this->remove($cart, $productId);

            return null;
        }

        return DB::transaction(function () use ($cart, $productId, $qty): CartItem {
            $item = CartItem::query()->where('cart_id', $cart->id)->where('product_id', $productId)->first()
                ?? throw new SalesException(__('sales.cart.not_in_cart'));

            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            $actions = $product !== null && $this->isVisible($product) ? $this->availability->actionsFor($product) : null;

            if ($product === null || $actions === null || ! $actions->canAddToCart) {
                throw new SalesException(__($actions !== null && $actions->outOfStock ? 'sales.cart.out_of_stock' : 'sales.cart.not_available', ['name' => $product->name ?? '']));
            }

            $item->qty = $actions->maxQty !== null ? min($qty, $actions->maxQty) : $qty;
            $item->save();
            $cart->touch();

            return $item;
        });
    }

    public function remove(Cart $cart, int $productId): void
    {
        CartItem::query()->where('cart_id', $cart->id)->where('product_id', $productId)->delete();
        $cart->touch();
    }

    /**
     * Header counter: total quantity of items.
     */
    public function count(Cart $cart): int
    {
        return (int) CartItem::query()->where('cart_id', $cart->id)->sum('qty');
    }

    /**
     * Header counter for the visitor's cart (one query; 0 without a cart cookie).
     */
    public function countForRequest(Request $request): int
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || ! Str::isUuid($token)) {
            return 0;
        }

        return (int) CartItem::query()
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('carts.token', $token)
            ->sum('cart_items.qty');
    }

    /**
     * "Understood" on the price-change notice: current prices become the reference prices (PR-07).
     */
    public function acknowledgePrices(Cart $cart): void
    {
        foreach ($cart->items()->with('product')->get() as $item) {
            if ($item->product !== null && Money::fromRubles($item->price_at_add) !== Money::fromRubles($item->product->final_price)) {
                $item->price_at_add = $item->product->final_price;
                $item->save();
            }
        }
    }

    /**
     * Everything the cart page needs (SF-42, SF-43, SF-45).
     */
    public function summary(Cart $cart): CartSummary
    {
        $lines = [];
        $total = 0;
        $changes = false;

        foreach ($cart->items()->with('product')->get() as $item) {
            $product = $item->product;
            $atAdd = Money::fromRubles($item->price_at_add);

            if ($product === null || ! $this->isVisible($product)) {
                $lines[] = new CartLine($item, $product, $item->qty, $atAdd, $atAdd, 0, false, true, null, __('sales.cart.issue_unavailable'));
                $changes = true;

                continue;
            }

            $actions = $this->availability->actionsFor($product);
            $current = Money::fromRubles($product->final_price);
            $priceChanged = $current !== $atAdd;
            $unavailable = ! $actions->canAddToCart;
            $issue = null;

            if ($unavailable) {
                $issue = __($actions->outOfStock ? 'sales.cart.out_of_stock' : 'sales.cart.issue_unavailable', ['name' => $product->name]);
            } elseif ($actions->maxQty !== null && $item->qty > $actions->maxQty) {
                $issue = __('sales.cart.issue_exceeds', ['max' => $actions->maxQty]);
            }

            $lineTotal = $unavailable ? 0 : $current * $item->qty;
            $total += $lineTotal;
            $changes = $changes || $priceChanged || $issue !== null;

            $lines[] = new CartLine($item, $product, $item->qty, $atAdd, $current, $lineTotal, $priceChanged, $unavailable, $actions->maxQty, $issue);
        }

        $deliveries = [];

        foreach ($this->methods->deliveries() as $method) {
            $price = $method->priceFor($total);
            $deliveries[] = ['method' => $method, 'price' => $price, 'free' => $price === 0 && $method->price > 0];
        }

        $min = $this->settings->min_order_amount;
        $missing = $min > 0 ? max($min - $total, 0) : 0;

        return new CartSummary($lines, $total, $deliveries, $min, $missing, $changes, $lines !== [] && $missing === 0);
    }

    /**
     * Deletes carts untouched for N days (their items go with them); returns the number of carts removed.
     */
    public function clearOld(int $days = 30): int
    {
        return Cart::query()->where('updated_at', '<', now()->subDays($days))->delete();
    }

    private function isVisible(Product $product): bool
    {
        return $product->is_active && $this->tree->isVisible($product->category_id);
    }
}
