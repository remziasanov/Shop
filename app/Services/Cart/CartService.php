<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\SalesException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CategoryTree;
use App\Services\Pricing\Priceable;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\ResolvedPrice;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\CheckoutMethods;
use App\Services\Stock\Stockable;
use App\Services\Variants\VariantLabel;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\QtyLimit;
use Illuminate\Database\UniqueConstraintViolationException;
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
        private readonly ViewerContext $viewer,
        private readonly VariantLabel $labels,
        private readonly PriceResolver $resolver,
    ) {}

    /**
     * The visitor's cart or null when they have none yet. A signed-in customer has one cart bound to the account
     * (CA-15); a guest's cart is found by the cookie and is never a customer cart.
     */
    public function current(Request $request): ?Cart
    {
        $customer = $this->viewer->customer();

        if ($customer !== null) {
            return Cart::query()->where('customer_id', $customer->id)->first();
        }

        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || ! Str::isUuid($token)) {
            return null;
        }

        return Cart::query()->where('token', $token)->whereNull('customer_id')->first();
    }

    /**
     * The visitor's cart, created on demand; for a guest it queues the cookie (refreshing its 30 days) on the
     * response. The cart of a customer is addressed by the account, so no cookie is set for it.
     */
    public function getOrCreate(Request $request): Cart
    {
        $customer = $this->viewer->customer();

        if ($customer !== null) {
            return $this->customerCart($customer);
        }

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
     * a price change stays visible. A card with variants needs the variant (VR-12): it must belong to the card and be
     * active, the price and the stock are those of the variant.
     *
     * @throws SalesException
     */
    public function add(Cart $cart, Product $product, int $qty = 1, ?int $variantId = null): CartItem
    {
        $this->viewer->assertNotPreview();

        if ($qty < 1) {
            throw new SalesException(__('sales.cart.qty_invalid'));
        }

        $qty = min($qty, QtyLimit::MAX);

        return DB::transaction(function () use ($cart, $product, $qty, $variantId): CartItem {
            // Lock order is cart, product, variant, the same as in checkout (no deadlock between "add" and "checkout").
            $this->lockCart($cart);

            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $unit = $locked !== null ? $this->lockUnit($locked, $variantId) : null;
            $actions = $unit !== null && $this->isVisible($locked) ? $this->availability->actionsFor($unit) : null;

            if ($locked === null || $unit === null || $actions === null || ! $actions->canAddToCart) {
                throw new SalesException(__($actions !== null && $actions->outOfStock ? 'sales.cart.out_of_stock' : 'sales.cart.not_available', ['name' => $unit?->stockName() ?? $product->name]));
            }

            $item = $this->itemOf($cart, $locked->id, $unit->stockVariantId());
            $total = min($qty + ($item->qty ?? 0), QtyLimit::MAX);

            if ($actions->maxQty !== null) {
                $total = min($total, $actions->maxQty);
            }

            // GP-11: the unit price of the row follows the quantity (tiers of the viewer's group).
            $priced = $this->priceOf($unit, $total);

            if ($item === null) {
                $item = CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'product_id' => $locked->id,
                    'variant_id' => $unit->stockVariantId(),
                    'qty' => $total,
                    'price_at_add' => Money::toRubles($priced->unitPrice),
                ]);
            } else {
                $this->moveQty($item, $unit, $total);
                $item->save();
            }

            $cart->touch();

            return $item;
        });
    }

    /**
     * The row of a product (or one of its variants) in the cart.
     */
    private function itemOf(Cart $cart, int $productId, ?int $variantId): ?CartItem
    {
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->where('variant_key', $variantId ?? 0)
            ->first();
    }

    /**
     * The unit that is sold under the locked card: the card itself, or its locked active variant (VR-12, VR-13). Null
     * when a card with variants gets no variant (or one of another card, inactive or deleted) and when a card without
     * variants gets one.
     */
    private function lockUnit(Product $locked, ?int $variantId): ?Stockable
    {
        if (! $locked->has_variants) {
            return $variantId === null ? $locked : null;
        }

        if ($variantId === null) {
            throw new SalesException(__('variant_storefront.errors.required', ['name' => $locked->name]));
        }

        $variant = ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $locked->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($variant === null) {
            throw new SalesException(__('variant_storefront.errors.unavailable', ['name' => $locked->name]));
        }

        return $variant->setRelation('product', $locked);
    }

    /**
     * Sets the quantity of a row; zero or less removes it. Limited by the available stock.
     *
     * @throws SalesException
     */
    public function update(Cart $cart, int $productId, int $qty, ?int $variantId = null): ?CartItem
    {
        if ($qty < 1) {
            $this->remove($cart, $productId, $variantId);

            return null;
        }

        $qty = min($qty, QtyLimit::MAX);

        return DB::transaction(function () use ($cart, $productId, $qty, $variantId): CartItem {
            $this->lockCart($cart);

            $item = $this->itemOf($cart, $productId, $variantId)
                ?? throw new SalesException(__('sales.cart.not_in_cart'));

            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            $unit = $product !== null ? $this->lockUnit($product, $variantId) : null;
            $actions = $unit !== null && $this->isVisible($product) ? $this->availability->actionsFor($unit) : null;

            if ($product === null || $unit === null || $actions === null || ! $actions->canAddToCart) {
                throw new SalesException(__($actions !== null && $actions->outOfStock ? 'sales.cart.out_of_stock' : 'sales.cart.not_available', ['name' => $unit?->stockName() ?? $product->name ?? '']));
            }

            $this->moveQty($item, $unit, $actions->maxQty !== null ? min($qty, $actions->maxQty) : $qty);
            $item->save();
            $cart->touch();

            return $item;
        });
    }

    /**
     * The price of one unit for the viewer's customer group and a quantity (GP-03, GP-11).
     */
    private function priceOf(Stockable $unit, int $qty, ?PriceBook $book = null): ResolvedPrice
    {
        return $this->resolver->resolve($this->priceable($unit), $this->viewer->group(), $qty, $book);
    }

    private function priceable(Stockable $unit): Priceable
    {
        return $unit instanceof Priceable ? $unit : throw new SalesException(__('sales.cart.not_available', ['name' => $unit->stockName()]));
    }

    /**
     * Sets the quantity of a row. A change of the quantity that moves the unit price over a tier boundary is the
     * customer's own doing, not a price change of the shop: the row is quoted again at the new price, so `price_at_add`
     * follows and no «цена изменилась» notice appears. Within one tier `price_at_add` is kept, so a real change of the
     * shop's price stays visible (SF-42).
     */
    private function moveQty(CartItem $item, Stockable $unit, int $qty): void
    {
        $book = $this->resolver->preload([$this->priceable($unit)], $this->viewer->group());
        $before = $this->priceOf($unit, $item->qty, $book)->unitPrice;
        $after = $this->priceOf($unit, $qty, $book)->unitPrice;

        if ($before !== $after) {
            $item->price_at_add = Money::toRubles($after);
        }

        $item->qty = $qty;
    }

    /**
     * First lock of every cart-changing transaction (OrderService::checkout() locks the cart before the products too).
     *
     * @throws SalesException when the cart no longer exists (it was checked out and removed meanwhile)
     */
    private function lockCart(Cart $cart): void
    {
        if (Cart::query()->whereKey($cart->id)->lockForUpdate()->first() === null) {
            throw new SalesException(__('sales.cart.gone'));
        }
    }

    public function remove(Cart $cart, int $productId, ?int $variantId = null): void
    {
        CartItem::query()->where('cart_id', $cart->id)->where('product_id', $productId)->where('variant_key', $variantId ?? 0)->delete();
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
        $customer = $this->viewer->customer();

        if ($customer !== null) {
            return (int) CartItem::query()
                ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                ->where('carts.customer_id', $customer->id)
                ->sum('cart_items.qty');
        }

        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || ! Str::isUuid($token)) {
            return 0;
        }

        return (int) CartItem::query()
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('carts.token', $token)
            ->whereNull('carts.customer_id')
            ->sum('cart_items.qty');
    }

    /**
     * «Повторить заказ» (CA-13): every position of the request goes through {@see add()} again, so the product, its
     * sales mode, availability, stock limit and the current price for the customer's group are decided now. Positions
     * that cannot be added are skipped and listed in the report.
     */
    public function addFromOrder(Cart $cart, Order $order): ReorderReport
    {
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $added = 0;
        $skipped = [];

        foreach ($items as $item) {
            $product = $item->product_id !== null ? Product::query()->find($item->product_id) : null;

            try {
                if ($product === null) {
                    throw new SalesException(__('sales.cart.not_available', ['name' => $item->name]));
                }

                $this->add($cart, $product, $item->qty, $item->variant_id);
                $added++;
            } catch (SalesException) {
                $skipped[] = $item->name;
            }
        }

        return new ReorderReport($added, $items->count(), $skipped);
    }

    /**
     * The cart of a customer, created on demand. A parallel request may create it first (unique `customer_id`): the
     * loser reads the winner's cart.
     */
    private function customerCart(Customer $customer): Cart
    {
        $cart = Cart::query()->where('customer_id', $customer->id)->first();

        if ($cart !== null) {
            return $cart;
        }

        try {
            $cart = new Cart(['token' => (string) Str::uuid()]);
            $cart->customer_id = $customer->id;
            $cart->save();

            return $cart;
        } catch (UniqueConstraintViolationException) {
            return Cart::query()->where('customer_id', $customer->id)->firstOrFail();
        }
    }

    /**
     * "Understood" on the price-change notice: current prices become the reference prices (PR-07).
     */
    public function acknowledgePrices(Cart $cart): void
    {
        $items = $cart->items()->with(['product', 'variant'])->get();
        $book = $this->bookOf($items);

        foreach ($items as $item) {
            $unit = $item->variant_id !== null ? $item->variant : $item->product;

            if ($unit === null) {
                continue;
            }

            $current = $this->resolver->resolve($unit, $this->viewer->group(), $item->qty, $book)->unitPrice;

            if (Money::fromRubles($item->price_at_add) !== $current) {
                $item->price_at_add = Money::toRubles($current);
                $item->save();
            }
        }
    }

    /**
     * The group prices of all rows of the cart in one load (GP-03).
     *
     * @param  iterable<CartItem>  $items  with `product` and `variant` loaded
     */
    private function bookOf(iterable $items): PriceBook
    {
        $units = [];

        foreach ($items as $item) {
            if ($item->product !== null) {
                $units[] = $item->product;
            }

            if ($item->variant !== null) {
                $units[] = $item->variant;
            }
        }

        return $this->resolver->preload($units, $this->viewer->group());
    }

    /**
     * Everything the cart page needs (SF-42, SF-43, SF-45).
     */
    public function summary(Cart $cart): CartSummary
    {
        $lines = [];
        $total = 0;
        $changes = false;
        $items = $cart->items()->with(['product', 'variant'])->get();
        $labels = $this->labels->forMany($items->pluck('variant')->filter());
        $book = $this->bookOf($items);
        $group = $this->viewer->group();

        foreach ($items as $item) {
            $product = $item->product;
            $variant = $item->variant;
            $label = $variant !== null ? ($labels[$variant->id] ?? null) : null;
            $atAdd = Money::fromRubles($item->price_at_add);
            // The unit that is sold: a variant must exist, be live and active, and belong to the card of the row.
            $brokenVariant = $item->variant_id !== null && ($variant === null || $variant->trashed() || ! $variant->is_active || (int) $variant->product_id !== (int) $item->product_id);
            $missingVariant = $item->variant_id === null && $product !== null && $product->has_variants;

            if ($product === null || ! $this->isVisible($product) || $brokenVariant || $missingVariant) {
                $lines[] = new CartLine($item, $product, $item->qty, $atAdd, $atAdd, 0, false, true, null, __('sales.cart.issue_unavailable'), $variant, $label);
                $changes = true;

                continue;
            }

            $unit = $variant !== null ? $variant->setRelation('product', $product) : $product;
            $actions = $this->availability->actionsFor($unit);
            // GP-11: the price of the group for the current quantity of the row.
            $resolved = $this->resolver->resolve($unit, $group, $item->qty, $book);
            $current = $resolved->unitPrice;
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

            $lines[] = new CartLine($item, $product, $item->qty, $atAdd, $current, $lineTotal, $priceChanged, $unavailable, $actions->maxQty, $issue, $variant, $label, $unavailable ? null : $this->resolver->tierHint($resolved, $item->qty), $resolved->source, $resolved->tierMinQty);
        }

        $deliveries = [];

        foreach ($this->methods->deliveries() as $method) {
            $price = $method->priceFor($total);
            $deliveries[] = ['method' => $method, 'price' => $price, 'free' => $price === 0 && $method->price > 0];
        }

        // CG-08: the group's minimum replaces the shop-wide one.
        $min = $this->viewer->access()->minOrderAmount ?? $this->settings->min_order_amount;
        $missing = $min > 0 ? max($min - $total, 0) : 0;

        return new CartSummary($lines, $total, $deliveries, $min, $missing, $changes, $lines !== [] && $missing === 0);
    }

    /**
     * Deletes carts untouched for N days (their items go with them); returns the number of carts removed.
     */
    /**
     * @param  ?float  $deadline  `microtime(true)` limit: deletes in chunks and stops when it is reached (the next run continues)
     */
    public function clearOld(int $days = 30, ?float $deadline = null): int
    {
        $threshold = now()->subDays($days);
        // A customer's cart lives three times longer than a guest's (CA-15): it is meant to wait for the next visit.
        $customerThreshold = now()->subDays($days * 3);
        $deleted = 0;

        do {
            $ids = Cart::query()
                ->where(function ($query) use ($threshold, $customerThreshold): void {
                    $query->where(fn ($guest) => $guest->whereNull('customer_id')->where('updated_at', '<', $threshold))
                        ->orWhere(fn ($own) => $own->whereNotNull('customer_id')->where('updated_at', '<', $customerThreshold));
                })
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += Cart::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === 1000 && ($deadline === null || microtime(true) < $deadline));

        return $deleted;
    }

    private function isVisible(Product $product): bool
    {
        // CG-14: the category and section of the viewer's group are checked on every cart operation.
        return $product->is_active
            && $this->tree->isVisible($product->category_id)
            && $this->viewer->access()->canSeeProduct($product);
    }
}
