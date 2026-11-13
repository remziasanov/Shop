<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Tiers, exact prices and denormalized group prices of one customer group for a list of items, loaded in a fixed number
 * of queries (GP-03: no database access in loops). Built by {@see PriceResolver::preload()}.
 */
final class PriceBook
{
    /** @var array<string, array<int, int>> item key => min_qty => price in kopecks, sorted by min_qty */
    private array $tiers = [];

    /** @var array<string, array{price: int, old: int|null}> */
    private array $groupRows = [];

    /** @var array<int, true> */
    private array $products = [];

    private function __construct(public readonly int $groupId) {}

    /**
     * @param  iterable<Priceable>  $items
     */
    public static function load(iterable $items, int $groupId): self
    {
        $book = new self($groupId);
        $productIds = [];

        foreach ($items as $item) {
            $productIds[$item->priceProductId()] = $item->priceProductId();
        }

        $book->products = array_fill_keys(array_keys($productIds), true);

        foreach (array_chunk(array_values($productIds), 500) as $chunk) {
            $tiers = DB::table('product_price_tiers')
                ->where('customer_group_id', $groupId)
                ->whereIn('product_id', $chunk)
                ->orderBy('min_qty')
                ->get(['product_id', 'variant_key', 'min_qty', 'price']);

            foreach ($tiers as $row) {
                $book->tiers[self::key((int) $row->product_id, (int) $row->variant_key)][(int) $row->min_qty] = Money::fromRubles((string) $row->price);
            }

            $rows = DB::table('product_group_prices_final')
                ->where('customer_group_id', $groupId)
                ->whereIn('product_id', $chunk)
                ->get(['product_id', 'variant_key', 'final_price', 'display_old_price']);

            foreach ($rows as $row) {
                $book->groupRows[self::key((int) $row->product_id, (int) $row->variant_key)] = [
                    'price' => Money::fromRubles((string) $row->final_price),
                    'old' => $row->display_old_price !== null ? Money::fromRubles((string) $row->display_old_price) : null,
                ];
            }
        }

        foreach ($book->tiers as $key => $tiers) {
            ksort($tiers);
            $book->tiers[$key] = $tiers;
        }

        return $book;
    }

    public function covers(Priceable $item, int $groupId): bool
    {
        return $this->groupId === $groupId && isset($this->products[$item->priceProductId()]);
    }

    /**
     * min_qty => price (kopecks), ascending; min_qty 1 is the exact group price.
     *
     * @return array<int, int>
     */
    public function tiers(Priceable $item): array
    {
        return $this->tiers[self::key($item->priceProductId(), $item->priceVariantId() ?? 0)] ?? [];
    }

    /**
     * @return array{price: int, old: int|null}|null
     */
    public function groupRow(Priceable $item): ?array
    {
        return $this->groupRows[self::key($item->priceProductId(), $item->priceVariantId() ?? 0)] ?? null;
    }

    private static function key(int $productId, int $variantKey): string
    {
        return $productId.':'.$variantKey;
    }
}
