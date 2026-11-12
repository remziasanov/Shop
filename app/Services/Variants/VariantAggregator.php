<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Services\Pricing\ProductPriceUpdater;
use Illuminate\Support\Facades\DB;

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

    public function __construct(private readonly ProductPriceUpdater $prices) {}

    /**
     * Run $callback and refresh every card touched through {@see refresh()} once at the end (bulk operations).
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
            return $callback();
        } finally {
            $this->depth--;

            if ($this->depth === 0) {
                $ids = $this->pending;
                $this->pending = [];

                foreach ($ids as $id) {
                    $this->refreshNow($id);
                }
            }
        }
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
     * Stock aggregates only (StockService calls this inside its transaction).
     *
     * @return bool whether the card row changed
     */
    public function rollupStock(int $productId): bool
    {
        $expected = $this->expectedStock([$productId])[$productId] ?? ['stock_qty' => null, 'reserved_qty' => 0];
        $row = DB::table('products')->where('id', $productId)->where('has_variants', true)->first(['stock_qty', 'reserved_qty']);

        if ($row === null || ($this->sameInt($row->stock_qty, $expected['stock_qty']) && (int) $row->reserved_qty === $expected['reserved_qty'])) {
            return false;
        }

        DB::table('products')->where('id', $productId)->update($expected);

        return true;
    }

    /**
     * Expected stock aggregates per card, computed from the variant rows.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{stock_qty: int|null, reserved_qty: int}>
     */
    public function expectedStock(array $productIds): array
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
            ->get();

        $result = [];

        foreach ($productIds as $id) {
            $result[$id] = ['stock_qty' => null, 'reserved_qty' => 0];
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

        foreach ($ids->chunk(200) as $chunk) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }

            $cards = DB::table('products')->whereIn('id', $chunk->all())
                ->get(['id', 'stock_qty', 'reserved_qty', 'final_price', 'final_price_max', 'display_old_price']);

            foreach ($cards as $card) {
                $rows = $this->cardDiscrepancies($card);

                if ($rows !== []) {
                    $this->refreshNow((int) $card->id);
                    array_push($fixed, ...$rows);
                }
            }
        }

        return $fixed;
    }

    private function refreshNow(int $productId): void
    {
        $this->rollupStock($productId);
        $this->prices->aggregatePrices($productId);
    }

    /**
     * @return list<array{product_id: int, field: string, stored: string|null, expected: string|null}>
     */
    private function cardDiscrepancies(object $card): array
    {
        $rows = [];
        $id = (int) $card->id;
        $stock = $this->expectedStock([$id])[$id];

        if (! $this->sameInt($card->stock_qty, $stock['stock_qty'])) {
            $rows[] = ['product_id' => $id, 'field' => 'stock_qty', 'stored' => $this->str($card->stock_qty), 'expected' => $this->str($stock['stock_qty'])];
        }

        if ((int) $card->reserved_qty !== $stock['reserved_qty']) {
            $rows[] = ['product_id' => $id, 'field' => 'reserved_qty', 'stored' => (string) $card->reserved_qty, 'expected' => (string) $stock['reserved_qty']];
        }

        $prices = $this->prices->expectedAggregatePrices($id);

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
