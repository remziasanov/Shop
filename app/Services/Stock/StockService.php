<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Settings\SalesSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes `products.stock_qty` / `products.reserved_qty` (D-046, TZ 4).
 *
 * Every operation runs in a DB transaction and locks the affected product rows with `lockForUpdate()` in id
 * order (no deadlocks), then re-reads the figures from the locked rows. The order row is locked too, so the
 * `orders.stock_applied` flag makes every operation idempotent: applying twice does nothing.
 *
 * Stock is processed only for products with `stock_qty IS NOT NULL`. When stock tracking is switched off in the
 * settings nothing is deducted, held or flagged. Returning / releasing / completing follow the order's own
 * `stock_applied` flag (a fact recorded at creation) so a request created while tracking was on is still
 * settled correctly after tracking is switched off.
 *
 * Quantities never go below zero. Callers must not write the two columns directly.
 */
final class StockService
{
    public function __construct(private readonly SalesSettings $settings) {}

    public function trackingEnabled(): bool
    {
        return $this->settings->stock_tracking;
    }

    /**
     * Sellable quantity: stock_qty - reserved_qty, never below zero; null when stock is not tracked
     * (by the shop or for this product). Reads the given model, take a fresh one for a decision.
     */
    public function available(Product $product): ?int
    {
        if (! $this->settings->stock_tracking) {
            return null;
        }

        $available = $product->availableQty();

        return $available === null ? null : max($available, 0);
    }

    /**
     * Absolute stock value for import / admin edits (null = stop tracking this product). Always applied,
     * regardless of the shop-level tracking switch (it is catalog data). Keeps the passed model in sync.
     */
    public function setStock(Product $product, ?int $qty): void
    {
        if ($qty !== null) {
            $qty = max($qty, 0);
        }

        DB::transaction(function () use ($product, $qty): void {
            $locked = $this->lockProducts([$product->id])->get($product->id);

            if ($locked !== null && $locked->stock_qty !== $qty) {
                $locked->stock_qty = $qty;
                $locked->saveQuietly();
            }

            $product->stock_qty = $qty;
            $product->syncOriginalAttribute('stock_qty');
        });
    }

    /**
     * Order created: stock_qty -= qty. Without «под заказ» a shortage throws; with it only the available part is
     * deducted (the rest is back-ordered).
     *
     * @throws InsufficientStockException
     */
    public function deductForOrder(Order $order): void
    {
        if (! $this->settings->stock_tracking) {
            return;
        }

        $this->run($order, false, function (Order $fresh, Collection $items, Collection $products): void {
            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product === null || $product->stock_qty === null) {
                    continue;
                }

                $available = max($product->stock_qty - $product->reserved_qty, 0);

                if ($item->qty > $available && ! $this->settings->allow_backorder) {
                    throw new InsufficientStockException($product, $available);
                }

                $applied = $this->settings->allow_backorder ? min($item->qty, $available) : $item->qty;

                $product->stock_qty -= $applied;
                $product->saveQuietly();
                $this->markApplied($item, $applied);
            }

            $this->markOrder($fresh, true);
        });
    }

    /**
     * Order cancelled: stock_qty += exactly what was deducted.
     */
    public function returnForOrder(Order $order): void
    {
        $this->run($order, true, function (Order $fresh, Collection $items, Collection $products): void {
            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product !== null && $product->stock_qty !== null && $item->stock_applied_qty > 0) {
                    $product->stock_qty += $item->stock_applied_qty;
                    $product->saveQuietly();
                }

                $this->markApplied($item, 0);
            }

            $this->markOrder($fresh, false);
        });
    }

    /**
     * Reservation created: reserved_qty += qty; the available stock must cover it.
     *
     * @throws InsufficientStockException
     */
    public function holdForReservation(Order $order): void
    {
        if (! $this->settings->stock_tracking) {
            return;
        }

        $this->run($order, false, function (Order $fresh, Collection $items, Collection $products): void {
            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product === null || $product->stock_qty === null) {
                    continue;
                }

                $available = max($product->stock_qty - $product->reserved_qty, 0);

                if ($item->qty > $available) {
                    throw new InsufficientStockException($product, $available);
                }

                $product->reserved_qty += $item->qty;
                $product->saveQuietly();
                $this->markApplied($item, $item->qty);
            }

            $this->markOrder($fresh, true);
        });
    }

    /**
     * Reservation cancelled or expired: reserved_qty -= held quantity.
     */
    public function releaseReservation(Order $order): void
    {
        $this->run($order, true, function (Order $fresh, Collection $items, Collection $products): void {
            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product !== null && $item->stock_applied_qty > 0) {
                    $product->reserved_qty = max($product->reserved_qty - $item->stock_applied_qty, 0);
                    $product->saveQuietly();
                }

                $this->markApplied($item, 0);
            }

            $this->markOrder($fresh, false);
        });
    }

    /**
     * Reservation picked up: reserved_qty -= held, stock_qty -= held.
     */
    public function completeReservation(Order $order): void
    {
        $this->run($order, true, function (Order $fresh, Collection $items, Collection $products): void {
            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product !== null && $item->stock_applied_qty > 0) {
                    $product->reserved_qty = max($product->reserved_qty - $item->stock_applied_qty, 0);

                    if ($product->stock_qty !== null) {
                        $product->stock_qty = max($product->stock_qty - $item->stock_applied_qty, 0);
                    }

                    $product->saveQuietly();
                }

                $this->markApplied($item, 0);
            }

            $this->markOrder($fresh, false);
        });
    }

    /**
     * Common frame: transaction, order lock, idempotency check, products locked in id order.
     *
     * @param  bool  $requiresApplied  true: only act on an order whose stock was applied; false: only on one whose was not
     * @param  callable(Order, Collection<int, OrderItem>, Collection<int, Product>): void  $work
     */
    private function run(Order $order, bool $requiresApplied, callable $work): void
    {
        DB::transaction(function () use ($order, $requiresApplied, $work): void {
            /** @var Order|null $fresh */
            $fresh = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            if ($fresh->stock_applied !== $requiresApplied) {
                // Nothing to do (already applied / already settled); show the caller the real flag.
                $order->stock_applied = $fresh->stock_applied;
                $order->syncOriginalAttribute('stock_applied');

                return;
            }

            $items = $fresh->items()->get()->filter(fn (OrderItem $item): bool => $item->product_id !== null)->values();
            $products = $this->lockProducts($items->pluck('product_id')->map(fn ($id): int => (int) $id)->all());

            $work($fresh, $items, $products);

            // Keep the caller's instances in sync.
            $order->stock_applied = $fresh->stock_applied;

            if ($order->relationLoaded('items')) {
                foreach ($order->items as $loaded) {
                    $match = $items->firstWhere('id', $loaded->id);

                    if ($match !== null) {
                        $loaded->stock_applied_qty = $match->stock_applied_qty;
                        $loaded->syncOriginalAttribute('stock_applied_qty');
                    }
                }
            }

            $order->syncOriginalAttribute('stock_applied');
        });
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function lockProducts(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Product::withTrashed()
            ->whereIn('id', array_values(array_unique($ids)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function markApplied(OrderItem $item, int $qty): void
    {
        $item->stock_applied_qty = $qty;
        $item->save();
    }

    private function markOrder(Order $order, bool $applied): void
    {
        $order->stock_applied = $applied;
        $order->save();
    }
}
