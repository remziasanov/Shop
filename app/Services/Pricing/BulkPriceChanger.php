<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BulkPriceOperation;
use App\Enums\PriceChangeSource;
use App\Models\Product;
use App\Rules\PositiveAmount;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk change of the base price by ±X % or ±N ₽ (AD-42, D-017). Arithmetic is done in integer kopecks with
 * bcmath; a percentage is rounded half-up to a kopeck, the result is never below zero. Changes go through
 * the model, so the observer records price history (source "bulk") and recalculates the final price.
 */
final class BulkPriceChanger
{
    public const PREVIEW_LIMIT = 20;

    public const CHUNK_SIZE = 100;

    /** Largest value of a decimal(12,2) column, in kopecks. */
    private const MAX_KOPECKS = 999_999_999_999;

    public function __construct(private readonly PriceChangeContext $context) {}

    /**
     * New price for an old one (both in kopecks).
     *
     * @param  string  $value  percent or rubles, "10", "12,5"
     *
     * @throws InvalidArgumentException when the value is not a positive number
     */
    public function newPrice(int $oldKopecks, BulkPriceOperation $operation, string $value): int
    {
        $number = $this->parseValue($value);

        if ($operation->isPercent()) {
            // old (kopecks) * percent / 100, exact with 6 decimals, then rounded half-up to a whole kopeck.
            $exact = bcdiv(bcmul((string) $oldKopecks, $number, 4), '100', 6);
            $delta = (int) bcadd($exact, '0.5', 0);
        } else {
            $delta = Money::fromRubles($number);
        }

        $new = $operation->isIncrease() ? $oldKopecks + $delta : $oldKopecks - $delta;

        return max(0, min($new, self::MAX_KOPECKS));
    }

    /**
     * Before/after rows (kopecks) for the first {@see PREVIEW_LIMIT} of the given products.
     *
     * @param  iterable<int>  $ids
     * @return list<array{id: int, name: string, sku: string, before: int, after: int}>
     *
     * @throws InvalidArgumentException when the value is not a positive number
     */
    public function preview(iterable $ids, BulkPriceOperation $operation, string $value): array
    {
        $this->parseValue($value);

        $first = collect($ids)->unique()->take(self::PREVIEW_LIMIT)->values()->all();

        $rows = [];

        foreach (Product::withTrashed()->whereIn('id', $first)->orderBy('id')->get(['id', 'name', 'sku', 'base_price']) as $product) {
            $before = Money::fromRubles($product->base_price);

            $rows[] = [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'before' => $before,
                'after' => $this->newPrice($before, $operation, $value),
            ];
        }

        return $rows;
    }

    /**
     * Change the base price of the given products. Returns the number of products whose price changed.
     *
     * @param  iterable<int>  $ids
     *
     * @throws InvalidArgumentException when the value is not a positive number
     */
    public function apply(iterable $ids, BulkPriceOperation $operation, string $value): int
    {
        $this->parseValue($value);

        return $this->context->run(PriceChangeSource::Bulk, null, function () use ($ids, $operation, $value): int {
            $changed = 0;

            foreach (collect($ids)->unique()->chunk(self::CHUNK_SIZE) as $chunk) {
                $changed += DB::transaction(function () use ($chunk, $operation, $value): int {
                    $count = 0;

                    foreach (Product::withTrashed()->whereIn('id', $chunk->values()->all())->orderBy('id')->get() as $product) {
                        $old = Money::fromRubles($product->base_price);
                        $new = $this->newPrice($old, $operation, $value);

                        if ($new === $old) {
                            continue;
                        }

                        $product->base_price = Money::toRubles($new);
                        $product->save();
                        $count++;
                    }

                    return $count;
                });
            }

            return $changed;
        });
    }

    private function parseValue(string $value): string
    {
        $number = PositiveAmount::normalize($value);

        if ($number === null || bccomp($number, '0', 6) <= 0) {
            throw new InvalidArgumentException('The value must be a positive number.');
        }

        return bcadd($number, '0', 4);
    }
}
