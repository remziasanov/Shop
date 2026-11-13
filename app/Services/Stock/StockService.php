<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\OrderType;
use App\Exceptions\EditStockShortageException;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Home\HomePageRenderer;
use App\Services\Variants\VariantAggregator;
use App\Settings\SalesSettings;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only place that changes `stock_qty` / `reserved_qty` of products and variants (D-046, TZ 4, VR-06).
 *
 * The unit of stock is a {@see Stockable}: a product without variants or one variant (an order item with `variant_id`).
 * Every operation runs in a DB transaction and locks, always in this order (19.5, no deadlocks): the order row, the
 * affected product rows by ascending id (also the cards of variants: this serializes purchases inside one card and keeps
 * its aggregates consistent), the affected variant rows by ascending id, and lastly the promo code row (stage 13, only
 * when a promo is applied or released). The figures are re-read from the locked rows. The `orders.stock_applied` flag
 * makes every operation idempotent: applying twice does nothing. Afterwards the aggregates of the affected cards with
 * variants are rolled up in the same transaction ({@see VariantAggregator}).
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
    public function __construct(
        private readonly SalesSettings $settings,
        private readonly VariantAggregator $aggregator,
    ) {}

    public function trackingEnabled(): bool
    {
        return $this->settings->stock_tracking;
    }

    /**
     * Sellable quantity: stock_qty - reserved_qty, never below zero; null when stock is not tracked
     * (by the shop or for this unit). Reads the given model, take a fresh one for a decision.
     */
    public function available(Stockable $stockable): ?int
    {
        if (! $this->settings->stock_tracking) {
            return null;
        }

        $available = $stockable->availableQty();

        return $available === null ? null : max($available, 0);
    }

    /**
     * Absolute stock value for import / admin edits (null = stop tracking this unit). Always applied,
     * regardless of the shop-level tracking switch (it is catalog data). Keeps the passed model in sync.
     * The stock of a card with variants is an aggregate and cannot be set.
     *
     * @throws LogicException for a card with variants
     */
    public function setStock(Stockable $stockable, ?int $qty): void
    {
        if ($qty !== null) {
            $qty = max($qty, 0);
        }

        $model = $this->model($stockable);
        $productId = $stockable->stockProductId();

        DB::transaction(function () use ($model, $stockable, $productId, $qty): void {
            $products = $this->lockProducts([$productId]);
            $variants = $stockable->stockVariantId() === null ? new Collection : $this->lockVariants([(int) $stockable->stockVariantId()]);

            $locked = $stockable->stockVariantId() === null ? $products->get($productId) : $variants->get((int) $stockable->stockVariantId());

            if ($stockable->stockVariantId() === null && $locked instanceof Product && $locked->has_variants) {
                throw new LogicException('The stock of a card with variants is an aggregate of its variants.');
            }

            if ($locked !== null && $locked->stock_qty !== $qty) {
                $locked->stock_qty = $qty;
                $locked->saveQuietly();
            }

            if ($stockable->stockVariantId() !== null) {
                $this->aggregator->rollupStock($productId);
            }

            $model->stock_qty = $qty;
            $model->syncOriginalAttribute('stock_qty');

            $this->forgetHomePage();
        });
    }

    /**
     * A card whose variants were switched off starts over as an ordinary product: nothing is held any more (VR-25 has
     * checked that no unfinished request holds its stock), the aggregate stock stays as the product's own.
     */
    public function clearReservedOfCard(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $locked = $this->lockProducts([$product->id])->get($product->id);

            if ($locked !== null && $locked->reserved_qty !== 0) {
                $locked->reserved_qty = 0;
                $locked->saveQuietly();
            }

            $product->reserved_qty = 0;
            $product->syncOriginalAttribute('reserved_qty');

            $this->forgetHomePage();
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

        $this->run($order, false, function (Order $fresh, Collection $items, Closure $unit): void {
            foreach ($items as $item) {
                $stockable = $unit($item);

                if ($stockable === null || $stockable->stock_qty === null) {
                    continue;
                }

                $available = max($stockable->stock_qty - $stockable->reserved_qty, 0);

                if ($item->qty > $available && ! $this->settings->allow_backorder) {
                    throw new InsufficientStockException($stockable, $available);
                }

                $applied = $this->settings->allow_backorder ? min($item->qty, $available) : $item->qty;

                $stockable->stock_qty -= $applied;
                $stockable->saveQuietly();
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
        $this->run($order, true, function (Order $fresh, Collection $items, Closure $unit): void {
            foreach ($items as $item) {
                $stockable = $unit($item);

                if ($stockable !== null && $stockable->stock_qty !== null && $item->stock_applied_qty > 0) {
                    $stockable->stock_qty += $item->stock_applied_qty;
                    $stockable->saveQuietly();
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

        $this->run($order, false, function (Order $fresh, Collection $items, Closure $unit): void {
            foreach ($items as $item) {
                $stockable = $unit($item);

                if ($stockable === null || $stockable->stock_qty === null) {
                    continue;
                }

                $available = max($stockable->stock_qty - $stockable->reserved_qty, 0);

                if ($item->qty > $available) {
                    throw new InsufficientStockException($stockable, $available);
                }

                $stockable->reserved_qty += $item->qty;
                $stockable->saveQuietly();
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
        $this->run($order, true, function (Order $fresh, Collection $items, Closure $unit): void {
            foreach ($items as $item) {
                $stockable = $unit($item);

                if ($stockable !== null && $item->stock_applied_qty > 0) {
                    $stockable->reserved_qty = max($stockable->reserved_qty - $item->stock_applied_qty, 0);
                    $stockable->saveQuietly();
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
        $this->run($order, true, function (Order $fresh, Collection $items, Closure $unit): void {
            foreach ($items as $item) {
                $stockable = $unit($item);

                if ($stockable !== null && $item->stock_applied_qty > 0) {
                    $stockable->reserved_qty = max($stockable->reserved_qty - $item->stock_applied_qty, 0);

                    if ($stockable->stock_qty !== null) {
                        $stockable->stock_qty = max($stockable->stock_qty - $item->stock_applied_qty, 0);
                    }

                    $stockable->saveQuietly();
                }

                $this->markApplied($item, 0);
            }

            $this->markOrder($fresh, false);
        });
    }

    /**
     * Request is being deleted: an active (non-final) request that still holds stock gives it back, so a deleted
     * reservation / order never keeps `reserved_qty` / `stock_qty` occupied. A request in a final status already
     * settled its stock (a completed order keeps its deduction). Idempotent.
     */
    public function settleForDeletion(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            /** @var Order|null $fresh */
            $fresh = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->stock_applied || $fresh->status->isFinal()) {
                return;
            }

            match ($fresh->type) {
                OrderType::Reservation => $this->releaseReservation($fresh),
                OrderType::Order => $this->returnForOrder($fresh),
                default => null,
            };

            $order->stock_applied = $fresh->stock_applied;
            $order->syncOriginalAttribute('stock_applied');
        });
    }

    /**
     * Common frame: transaction, order lock, idempotency check, then products and variants locked in id order and the
     * card aggregates rolled up. `$work` receives the order, its items and a resolver
     * `fn (OrderItem): Product|ProductVariant|null` that returns the locked stockable of an item (null: nothing to do).
     *
     * @param  bool  $requiresApplied  true: only act on an order whose stock was applied; false: only on one whose was not
     * @param  callable(Order, Collection<int, OrderItem>, Closure(OrderItem): (Product|ProductVariant|null)): void  $work
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
            $lines = $items->map(fn (OrderItem $item): StockLine => StockLine::fromItem($item));

            // 19.5: products first (also the cards of variants), then variants, each ascending by id.
            $products = $this->lockProducts($lines->pluck('productId')->all());
            $variants = $this->lockVariants($lines->pluck('variantId')->filter()->all());

            // The cards are locked already: hand them to the variants, so messages need no extra query.
            foreach ($variants as $variant) {
                $card = $products->get($variant->product_id);

                if ($card !== null) {
                    $variant->setRelation('product', $card);
                }
            }

            $unit = function (OrderItem $item) use ($products, $variants): Product|ProductVariant|null {
                if ($item->variant_id !== null) {
                    return $variants->get((int) $item->variant_id);
                }

                $product = $products->get((int) $item->product_id);

                // A card with variants has no stock of its own: a line without variant changes nothing.
                return $product !== null && ! $product->has_variants ? $product : null;
            };

            $work($fresh, $items, $unit);

            foreach ($lines->filter(fn (StockLine $line): bool => $line->variantId !== null)->pluck('productId')->unique() as $productId) {
                $this->aggregator->rollupStock((int) $productId);
            }

            $this->forgetHomePage();

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
     * Stock part of an order edit (OE-04, 24.5): the only write of stock when the items of an order are edited. Locks
     * the order, the products and the variants (ascending ids) and counts the change per line from its old quantity:
     * no change in quantity touches nothing; an increase deducts (order) / holds (reservation) what is available - all of
     * it, or without «под заказ» a shortage rejects the whole edit; a decrease first drops the part of the line that was
     * never applied and returns / releases only the rest; a removed line (`newQty` 0) returns what it had applied. A
     * request whose `stock_applied` flag is off, and a unit with `stock_qty IS NULL`, are not counted at all.
     *
     * Returns one effect per change, in the order of `$changes`; the caller stores `appliedQty` on the lines.
     *
     * @param  list<StockChange>  $changes
     * @return list<StockEffect>
     *
     * @throws EditStockShortageException
     */
    public function applyItemChanges(Order $order, array $changes): array
    {
        return DB::transaction(function () use ($order, $changes): array {
            /** @var Order $fresh */
            $fresh = Order::withTrashed()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // 19.5: products first (also the cards of variants), then variants, each ascending by id.
            $products = $this->lockProducts(array_map(fn (StockChange $change): int => $change->productId, $changes));
            $variants = $this->lockVariants(array_values(array_filter(array_map(fn (StockChange $change): ?int => $change->variantId, $changes))));
            $effects = $this->planItemChanges($fresh, $changes, $products, $variants);

            $shortages = [];

            foreach ($effects as $effect) {
                if ($effect->shortage !== null) {
                    $shortages[$effect->name] = $effect->shortage;
                }
            }

            if ($shortages !== []) {
                throw new EditStockShortageException($shortages);
            }

            foreach ([...$products->all(), ...$variants->all()] as $model) {
                if ($model->isDirty()) {
                    $model->saveQuietly();
                }
            }

            foreach (collect($changes)->filter(fn (StockChange $change): bool => $change->variantId !== null)->pluck('productId')->unique() as $productId) {
                $this->aggregator->rollupStock((int) $productId);
            }

            $this->forgetHomePage();

            return $effects;
        });
    }

    /**
     * The same plan as {@see applyItemChanges()} without locks and without writing anything (the preview of the editor,
     * OE-10). A shortage is reported in the effect instead of thrown.
     *
     * @param  list<StockChange>  $changes
     * @return list<StockEffect>
     */
    public function previewItemChanges(Order $order, array $changes): array
    {
        $fresh = Order::withTrashed()->whereKey($order->getKey())->first() ?? $order;
        $products = Product::withTrashed()->whereIn('id', array_values(array_unique(array_map(fn (StockChange $change): int => $change->productId, $changes))))->get()->keyBy('id');
        $variantIds = array_values(array_unique(array_filter(array_map(fn (StockChange $change): ?int => $change->variantId, $changes))));
        $variants = $variantIds === [] ? new Collection : ProductVariant::withTrashed()->whereIn('id', $variantIds)->get()->keyBy('id');

        return $this->planItemChanges($fresh, $changes, $products, $variants);
    }

    /**
     * Counts the effects and applies them to the given models in memory (the caller saves them or throws them away).
     *
     * @param  list<StockChange>  $changes
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<StockEffect>
     */
    private function planItemChanges(Order $order, array $changes, Collection $products, Collection $variants): array
    {
        foreach ($variants as $variant) {
            $card = $products->get($variant->product_id);

            if ($card !== null) {
                $variant->setRelation('product', $card);
            }
        }

        $reservation = $order->type === OrderType::Reservation;
        $backorder = ! $reservation && $this->settings->allow_backorder;
        $effects = [];

        foreach ($changes as $change) {
            // The unit: a variant, or a product without variants (a card with variants has no stock of its own).
            if ($change->variantId !== null) {
                $unit = $variants->get($change->variantId);
            } else {
                $card = $products->get($change->productId);
                $unit = $card !== null && ! $card->has_variants ? $card : null;
            }

            $name = $unit?->stockName() ?? (string) ($products->get($change->productId)->name ?? '');
            $applied = min($change->appliedQty, $change->newQty);

            // A reservation line whose unit lost its stock figure after the hold (stock_qty NULL now) still gives back
            // what it holds when it shrinks or goes away, like releaseReservation() does (D-051 spirit).
            if ($reservation && $order->stock_applied && $unit !== null && $unit->stock_qty === null
                && $change->newQty < $change->oldQty && $change->appliedQty > 0) {
                $give = $change->oldQty - $change->newQty;
                $toReturn = $give - min($give, $change->oldQty - $change->appliedQty);
                $applied = min($change->appliedQty - $toReturn, $change->newQty);
                $release = min($change->appliedQty - $applied, $unit->reserved_qty);
                $unit->reserved_qty -= $release;
                $effects[] = new StockEffect($change->key, $name, $applied, reservedDelta: -$release, tracked: true);

                continue;
            }

            if (! $order->stock_applied || $unit === null || $unit->stock_qty === null) {
                $effects[] = new StockEffect($change->key, $name, $applied);

                continue;
            }

            if ($change->newQty === $change->oldQty) {
                $effects[] = new StockEffect($change->key, $name, $applied, tracked: true);

                continue;
            }

            $delta = 0;

            if ($change->newQty > $change->oldQty) {
                $take = $change->newQty - $change->oldQty;
                $available = max($unit->stock_qty - $unit->reserved_qty, 0);

                if ($take > $available && ! $backorder) {
                    $effects[] = new StockEffect($change->key, $name, $applied, shortage: $available, tracked: true);

                    continue;
                }

                $taken = $backorder ? min($take, $available) : $take;
                $delta = $taken;
                $applied = min($change->appliedQty + $taken, $change->newQty);
            } else {
                $give = $change->oldQty - $change->newQty;
                $fromUnapplied = min($give, $change->oldQty - $change->appliedQty);
                $toReturn = $give - $fromUnapplied;
                $delta = -$toReturn;
                $applied = min($change->appliedQty - $toReturn, $change->newQty);
            }

            if ($reservation) {
                $unit->reserved_qty = max($unit->reserved_qty + $delta, 0);
                $effects[] = new StockEffect($change->key, $name, $applied, reservedDelta: $delta, tracked: true);
            } else {
                $unit->stock_qty = max($unit->stock_qty - $delta, 0);
                $effects[] = new StockEffect($change->key, $name, $applied, stockDelta: -$delta, tracked: true);
            }
        }

        return $effects;
    }

    /**
     * The model that carries the figures of a stockable: a product or a variant.
     */
    private function model(Stockable $stockable): Product|ProductVariant
    {
        if ($stockable instanceof Product || $stockable instanceof ProductVariant) {
            return $stockable;
        }

        throw new LogicException('A stockable is a product or a variant.');
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

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ProductVariant>
     */
    private function lockVariants(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return ProductVariant::withTrashed()
            ->whereIn('id', array_values(array_unique($ids)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Stock is written with `saveQuietly()` (no model events), but the cached home page shows availability:
     * drop it once the transaction commits.
     */
    private function forgetHomePage(): void
    {
        DB::afterCommit(static fn () => HomePageRenderer::forget());
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
