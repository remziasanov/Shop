<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\BulkPriceOperation;
use App\Enums\PriceChangeSource;
use App\Jobs\ApplyBulkPriceChange;
use App\Models\Product;
use App\Models\User;
use App\Rules\PositiveAmount;
use App\Services\Users\PermissionService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bulk change of the base price by ±X % or ±N ₽ (AD-42, D-017). Arithmetic is done in integer kopecks with
 * bcmath; a percentage is rounded half-up to a kopeck, the result is never below zero. Changes go through
 * the model, so the observer records price history (source "bulk") and recalculates the final price. The work
 * runs in queued {@see ApplyBulkPriceChange} jobs (D-032).
 */
final class BulkPriceChanger
{
    public const PREVIEW_LIMIT = 20;

    public const CHUNK_SIZE = 100;

    /** Products per queued job (about 4 ms each, the job limit is 40 s). */
    public const JOB_SIZE = 200;

    /** Largest value of a decimal(12,2) column, in kopecks. */
    private const MAX_KOPECKS = 999_999_999_999;

    public function __construct(
        private readonly PriceChangeContext $context,
        private readonly PermissionService $permissions,
    ) {}

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
     * The change for each product whose base price would actually change: id => [old, new] in kopecks,
     * computed in chunks of {@see CHUNK_SIZE}. The "old" price is what {@see applyTargets()} later checks, so a
     * retried or duplicated job never applies the change twice.
     *
     * @param  iterable<int>  $ids
     * @return array<int, array{old: int, new: int}>
     *
     * @throws InvalidArgumentException when the value is not a positive number
     */
    public function computeTargets(iterable $ids, BulkPriceOperation $operation, string $value): array
    {
        $this->parseValue($value);

        $targets = [];

        foreach (collect($ids)->unique()->chunk(self::CHUNK_SIZE) as $chunk) {
            $products = Product::withTrashed()->whereIn('id', $chunk->values()->all())->orderBy('id')->get(['id', 'base_price']);

            foreach ($products as $product) {
                $old = Money::fromRubles($product->base_price);
                $new = $this->newPrice($old, $operation, $value);

                if ($new !== $old) {
                    $targets[$product->id] = ['old' => $old, 'new' => $new];
                }
            }
        }

        return $targets;
    }

    /**
     * Queue the change as {@see ApplyBulkPriceChange} jobs of up to {@see JOB_SIZE} products (a job must stay
     * within 40 s on shared hosting). The acting user is passed along, because queue workers have no session.
     * The change needs the `prices.bulk` right of the actor (RB-11), whatever UI calls this.
     *
     * @param  iterable<int>  $ids
     * @return array{products: int, jobs: int}
     *
     * @throws InvalidArgumentException when the value is not a positive number
     * @throws AuthorizationException when the actor lacks `prices.bulk`
     */
    public function dispatch(iterable $ids, BulkPriceOperation $operation, string $value, User $actor): array
    {
        if (! $this->permissions->allows($actor, 'prices.bulk')) {
            throw new AuthorizationException;
        }

        $userId = (int) $actor->getKey();
        $targets = $this->computeTargets($ids, $operation, $value);

        foreach (array_chunk($targets, self::JOB_SIZE, true) as $chunk) {
            ApplyBulkPriceChange::dispatch($chunk, $userId)->afterCommit();
        }

        return ['products' => count($targets), 'jobs' => (int) ceil(count($targets) / self::JOB_SIZE)];
    }

    /**
     * Set the new base price of each product, but only when its current base price still equals the expected
     * old one (idempotent; products changed meanwhile are skipped). Runs in the bulk context of the given user,
     * so history rows get source "bulk" and the final price is recalculated by the observer.
     *
     * @param  array<int, array{old: int, new: int}>  $targets
     * @return array{changed: int, skipped: int}
     */
    public function applyTargets(array $targets, ?int $userId): array
    {
        return $this->context->run(PriceChangeSource::Bulk, null, function () use ($targets): array {
            $changed = 0;
            $skipped = 0;

            foreach (array_chunk($targets, self::CHUNK_SIZE, true) as $chunk) {
                DB::transaction(function () use ($chunk, &$changed, &$skipped): void {
                    $products = Product::withTrashed()->whereIn('id', array_keys($chunk))->orderBy('id')->lockForUpdate()->get();

                    $skipped += count($chunk) - $products->count();

                    foreach ($products as $product) {
                        $target = $chunk[$product->id];

                        $current = Money::fromRubles($product->base_price);

                        // Already applied by an earlier attempt of the same job (retry): neither change nor skip.
                        if ($current === $target['new']) {
                            continue;
                        }

                        if ($current !== $target['old']) {
                            $skipped++;

                            continue;
                        }

                        $product->base_price = Money::toRubles($target['new']);
                        $product->save();
                        $changed++;
                    }
                });
            }

            return ['changed' => $changed, 'skipped' => $skipped];
        }, $userId);
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
