<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Services\Pricing\GroupPriceRecalculator;
use App\Services\Pricing\ProductPriceUpdater;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Card aggregates over the variants (VR-06, VR-07, 19.5):
 *
 *  - stock_qty    = SUM(stock_qty) over the active variants; NULL when there is no active variant or at least one
 *                   active variant is not tracked;
 *  - reserved_qty = SUM(reserved_qty) over the active variants;
 *  - final_price / final_price_max / display_old_price: see {@see ProductPriceUpdater::aggregatePrices()}.
 *
 * Aggregates are written with plain queries (no model events) and only for cards with `has_variants`. They serve lists,
 * sorting and filters; availability decisions are made on the variant.
 */
final class VariantAggregator
{
    private int $depth = 0;

    /** @var array<int, int> */
    private array $pending = [];

    public function __construct(
        private readonly ProductPriceUpdater $prices,
        private readonly GroupPriceRecalculator $groupPrices,
    ) {}

    /**
     * Run $callback and refresh every card touched through {@see refresh()} once at the end (bulk operations).
     * Call it INSIDE the transaction that writes the variants: the refresh then runs in that transaction, after the card
     * row was locked (19.5). When the callback throws, nothing is refreshed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function batch(callable $callback): mixed
    {
        $this->depth++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->depth--;

            if ($this->depth === 0) {
                $this->pending = [];
            }

            throw $e;
        }

        $this->depth--;

        if ($this->depth === 0) {
            $ids = $this->pending;
            $this->pending = [];

            foreach ($ids as $id) {
                $this->refreshNow($id);
            }
        }

        return $result;
    }

    /**
     * Recompute stock and price aggregates of a card (deferred inside {@see batch()}).
     */
    public function refresh(int $productId): void
    {
        if ($this->depth > 0) {
            $this->pending[$productId] = $productId;

            return;
        }

        $this->refreshNow($productId);
    }

    /**
     * Stock aggregates only (StockService calls this inside its transaction). Locks the card row first, then reads the
     * variants (lock order products, then variants, 19.5); a trashed card is left alone.
     *
     * @return bool whether the card row changed
     */
    public function rollupStock(int $productId): bool
    {
        return DB::transaction(function () use ($productId): bool {
            $row = $this->lockCard($productId);

            return $row !== null && $this->writeStock($row);
        });
    }

    /**
     * Expected stock aggregates per card, computed from the variant rows. A card with variants but no active one is
     * sold out: stock 0 and nothing reserved (VR-08, D-114). NULL (not tracked, always available) only when an active
     * variant is not tracked.
     *
     * @param  list<int>  $productIds
     * @param  bool  $lock  read the variants with FOR UPDATE: a current read, not the transaction snapshot. Writers need it
     *                      (MySQL REPEATABLE READ would otherwise show the rows as they were when the transaction began)
     * @return array<int, array{stock_qty: int|null, reserved_qty: int}>
     */
    public function expectedStock(array $productIds, bool $lock = false): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = DB::table('product_variants')
            ->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as active_count, SUM(CASE WHEN stock_qty IS NULL THEN 1 ELSE 0 END) as untracked, SUM(stock_qty) as stock_sum, SUM(reserved_qty) as reserved_sum')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        $result = [];

        foreach ($productIds as $id) {
            $result[$id] = ['stock_qty' => 0, 'reserved_qty' => 0];
        }

        foreach ($rows as $row) {
            $tracked = (int) $row->untracked === 0 && (int) $row->active_count > 0;

            $result[(int) $row->product_id] = [
                'stock_qty' => $tracked ? (int) $row->stock_sum : null,
                'reserved_qty' => (int) $row->reserved_sum,
            ];
        }

        return $result;
    }

    /**
     * Cards whose stored aggregates differ from the variants, as ['product_id', 'field', 'stored', 'expected'] rows.
     * Used by `shop:variants-rollup` and the doctor check. Read-only; chunked by 200 cards.
     *
     * @return list<array{product_id: int, field: string, stored: string|null, expected: string|null}>
     */
    public function discrepancies(?int $limit = null): array
    {
        $found = [];

        $query = DB::table('products')->where('has_variants', true)->whereNull('deleted_at');

        foreach ($query->orderBy('id')->select(['id', 'stock_qty', 'reserved_qty', 'final_price', 'final_price_max', 'display_old_price'])->lazyById(200, 'id') as $card) {
            foreach ($this->cardDiscrepancies($card) as $row) {
                $found[] = $row;
            }

            if ($limit !== null && count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    /**
     * Recompute the aggregates of every card with variants; returns the discrepancies that were fixed.
     *
     * @param  float|null  $deadline  microtime(true) after which processing stops (the rest waits for the next run)
     * @return list<array{product_id: int, field: string, stored: string|null, expected: string|null}>
     */
    public function rollupAll(?float $deadline = null): array
    {
        $fixed = [];

        $ids = DB::table('products')->where('has_variants', true)->whereNull('deleted_at')->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            // The card is locked before its variants are read, so a concurrent checkout cannot be overwritten.
            $rows = DB::transaction(function () use ($id): array {
                $card = $this->lockCard((int) $id);
                $rows = $card === null ? [] : $this->cardDiscrepancies($card, true);

                if ($card !== null && $rows !== []) {
                    $this->writeStock($card);
                    $this->prices->aggregatePrices((int) $id);
                }

                return $rows;
            });

            array_push($fixed, ...$rows);
        }

        return $fixed;
    }

    private function refreshNow(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $card = $this->lockCard($productId);

            if ($card !== null) {
                $this->writeStock($card);
                $this->prices->aggregatePrices($productId);
                // GP-06: the card row of the group prices is the minimum over the variants.
                $this->groupPrices->recalculate([$productId]);
            }
        });
    }

    /**
     * Locks the row of a live card with variants (FOR UPDATE) and returns its aggregate columns; null for any other
     * product. Must run inside a transaction and before the variants are read.
     */
    private function lockCard(int $productId): ?object
    {
        return DB::table('products')
            ->where('id', $productId)
            ->where('has_variants', true)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id', 'stock_qty', 'reserved_qty', 'final_price', 'final_price_max', 'display_old_price']);
    }

    /**
     * Writes the expected stock aggregates of a locked card when they differ.
     */
    private function writeStock(object $card): bool
    {
        $id = (int) $card->id;
        $expected = $this->expectedStock([$id], true)[$id];

        if ($this->sameInt($card->stock_qty, $expected['stock_qty']) && (int) $card->reserved_qty === $expected['reserved_qty']) {
            return false;
        }

        DB::table('products')->where('id', $id)->update($expected);

        return true;
    }

    /**
     * @return list<array{product_id: int, field: string, stored: string|null, expected: string|null}>
     */
    private function cardDiscrepancies(object $card, bool $lock = false): array
    {
        $rows = [];
        $id = (int) $card->id;
        $stock = $this->expectedStock([$id], $lock)[$id];

        if (! $this->sameInt($card->stock_qty, $stock['stock_qty'])) {
            $rows[] = ['product_id' => $id, 'field' => 'stock_qty', 'stored' => $this->str($card->stock_qty), 'expected' => $this->str($stock['stock_qty'])];
        }

        if ((int) $card->reserved_qty !== $stock['reserved_qty']) {
            $rows[] = ['product_id' => $id, 'field' => 'reserved_qty', 'stored' => (string) $card->reserved_qty, 'expected' => (string) $stock['reserved_qty']];
        }

        $prices = $this->prices->expectedAggregatePrices($id, $lock);

        if ($prices !== null) {
            foreach (['final_price', 'final_price_max', 'display_old_price'] as $field) {
                if (! $this->sameMoney($card->{$field}, $prices[$field])) {
                    $rows[] = ['product_id' => $id, 'field' => $field, 'stored' => $this->str($card->{$field}), 'expected' => $this->str($prices[$field])];
                }
            }
        }

        return $rows;
    }

    private function sameInt(mixed $stored, ?int $expected): bool
    {
        return ($stored === null ? null : (int) $stored) === $expected;
    }

    private function sameMoney(mixed $stored, ?string $expected): bool
    {
        if ($stored === null || $expected === null) {
            return $stored === null && $expected === null;
        }

        return bccomp((string) $stored, $expected, 2) === 0;
    }

    private function str(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
