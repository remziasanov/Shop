<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\GroupPriceBulkOperation;
use App\Enums\PriceChangeSource;
use App\Jobs\ApplyGroupPriceBulkChange;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\User;
use App\Rules\PositiveAmount;
use App\Services\Catalog\CatalogVersion;
use App\Services\Users\PermissionService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The bulk action «Цены группы» of the product table (GP-18): for the chosen products set the exact price of a customer
 * group to «retail − X %» or «cost + X %», or delete the exact prices of the group (tiers stay). A card with variants
 * is handled variant by variant (VR-20). Percentages are bcmath half-up in kopecks; exact prices are never rounded
 * (GP-21).
 *
 * The preview shows the first {@see PREVIEW_LIMIT} items like AD-42; {@see apply()} works in chunks of
 * {@see CHUNK_SIZE} products, each in a transaction, writes the price history (source «bulk») and recomputes the group
 * prices of the chunk. An item is skipped when the new price cannot be computed (`no_cost`, `invalid_price`) or would be
 * below an existing tier of the group (`tier_conflict`: a tier must not cost more than the exact price).
 */
final class GroupPriceBulkChanger
{
    public const PREVIEW_LIMIT = 20;

    public const CHUNK_SIZE = 200;

    public function __construct(
        private readonly PriceChangeContext $context,
        private readonly GroupPriceRecalculator $groupPrices,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @param  iterable<int>  $productIds
     * @return array{count: int, rows: list<array{product_id: int, variant_id: int|null, name: string, sku: string, before: int|null, after: int|null, skipped: string|null}>}
     *
     * @throws InvalidArgumentException when the value is missing or invalid for the operation
     */
    public function preview(iterable $productIds, CustomerGroup $group, GroupPriceBulkOperation $operation, ?string $value = null): array
    {
        $number = $this->parseValue($operation, $value);
        $ids = collect($productIds)->unique()->values();
        $rows = [];

        foreach ($ids->chunk(self::PREVIEW_LIMIT) as $chunk) {
            foreach ($this->plan($chunk->all(), $group, $operation, $number) as $item) {
                $rows[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'before' => $item['before'],
                    'after' => $item['after'],
                    'skipped' => $item['skipped'],
                ];

                if (count($rows) >= self::PREVIEW_LIMIT) {
                    break 2;
                }
            }
        }

        return ['count' => $ids->count(), 'rows' => $rows];
    }

    /**
     * Apply the operation to the products in chunks.
     *
     * @param  iterable<int>  $productIds
     *
     * @throws InvalidArgumentException when the value is missing or invalid for the operation
     */
    public function apply(iterable $productIds, CustomerGroup $group, GroupPriceBulkOperation $operation, ?string $value = null, ?int $userId = null): GroupBulkResult
    {
        $number = $this->parseValue($operation, $value);

        return $this->context->run(PriceChangeSource::Bulk, null, function () use ($productIds, $group, $operation, $number): GroupBulkResult {
            $result = new GroupBulkResult;

            foreach (collect($productIds)->unique()->chunk(self::CHUNK_SIZE) as $chunk) {
                $ids = $chunk->values()->all();
                $result = $result->merge(DB::transaction(fn (): GroupBulkResult => $this->applyChunk($ids, $group, $operation, $number)));
            }

            if ($result->changed > 0) {
                CatalogVersion::bump();
            }

            return $result;
        }, $userId);
    }

    /**
     * Queue the change as {@see ApplyGroupPriceBulkChange} jobs of {@see CHUNK_SIZE} products. Needs `prices.bulk` (RB-11).
     *
     * @param  iterable<int>  $productIds
     * @return array{products: int, jobs: int}
     *
     * @throws AuthorizationException when the actor lacks `prices.bulk`
     * @throws InvalidArgumentException when the value is missing or invalid for the operation
     */
    public function dispatch(iterable $productIds, CustomerGroup $group, GroupPriceBulkOperation $operation, ?string $value, User $actor): array
    {
        if (! $this->permissions->allows($actor, 'prices.bulk')) {
            throw new AuthorizationException;
        }

        $this->parseValue($operation, $value);
        $ids = collect($productIds)->unique()->values();

        foreach ($ids->chunk(self::CHUNK_SIZE) as $chunk) {
            ApplyGroupPriceBulkChange::dispatch($chunk->values()->all(), $group->id, $operation->value, $value, (int) $actor->getKey())->afterCommit();
        }

        return ['products' => $ids->count(), 'jobs' => (int) ceil($ids->count() / self::CHUNK_SIZE)];
    }

    /**
     * @param  list<int>  $ids
     */
    private function applyChunk(array $ids, CustomerGroup $group, GroupPriceBulkOperation $operation, ?string $number): GroupBulkResult
    {
        $changed = 0;
        $unchanged = 0;
        $skipped = [];
        $upserts = [];
        $deletes = [];
        $history = [];
        $now = now();

        foreach ($this->plan($ids, $group, $operation, $number) as $item) {
            if ($item['skipped'] !== null) {
                $skipped[$item['skipped']] = ($skipped[$item['skipped']] ?? 0) + 1;

                continue;
            }

            if ($item['before'] === $item['after']) {
                $unchanged++;

                continue;
            }

            $changed++;

            if ($item['after'] === null) {
                $deletes[] = [$item['product_id'], $item['variant_id'] ?? 0];
            } else {
                $upserts[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'variant_key' => $item['variant_id'] ?? 0,
                    'customer_group_id' => $group->id,
                    'min_qty' => 1,
                    'price' => Money::toRubles($item['after']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $history[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'customer_group_id' => $group->id,
                'min_qty' => 1,
                'field' => 'group_price',
                'old_value' => $item['before'] !== null ? Money::toRubles($item['before']) : null,
                'new_value' => $item['after'] !== null ? Money::toRubles($item['after']) : null,
                'source' => $this->context->source()->value,
                'user_id' => $this->context->userId(),
                'import_id' => null,
                'created_at' => $now,
            ];
        }

        foreach ($deletes as [$productId, $variantKey]) {
            DB::table('product_price_tiers')
                ->where('customer_group_id', $group->id)
                ->where('product_id', $productId)
                ->where('variant_key', $variantKey)
                ->where('min_qty', 1)
                ->delete();
        }

        if ($upserts !== []) {
            DB::table('product_price_tiers')->upsert($upserts, ['product_id', 'variant_key', 'customer_group_id', 'min_qty'], ['variant_id', 'price', 'updated_at']);
        }

        if ($history !== []) {
            DB::table('price_history')->insert($history);
            $this->groupPrices->recalculate($ids, [$group->id]);
        }

        return new GroupBulkResult($changed, $unchanged, $skipped);
    }

    /**
     * The planned change of every priced item of the products, ordered by product and variant.
     *
     * @param  list<int>  $ids
     * @return list<array{product_id: int, variant_id: int|null, name: string, sku: string, before: int|null, after: int|null, skipped: string|null}>
     */
    private function plan(array $ids, CustomerGroup $group, GroupPriceBulkOperation $operation, ?string $number): array
    {
        $products = Product::query()->whereIn('id', $ids)->orderBy('id')->get([
            'id', 'name', 'sku', 'has_variants', 'cost_price', 'final_price',
        ]);

        $variants = DB::table('product_variants')
            ->whereIn('product_id', $products->where('has_variants', true)->pluck('id')->all())
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'cost_price', 'final_price'])
            ->groupBy('product_id');

        $tiers = [];

        foreach (DB::table('product_price_tiers')->where('customer_group_id', $group->id)->whereIn('product_id', $ids)->get(['product_id', 'variant_key', 'min_qty', 'price']) as $row) {
            $tiers[$row->product_id.':'.$row->variant_key][(int) $row->min_qty] = Money::fromRubles((string) $row->price);
        }

        $plan = [];

        foreach ($products as $product) {
            $items = [];

            if ($product->has_variants) {
                foreach ($variants->get($product->id, collect()) as $variant) {
                    $items[] = [(int) $variant->id, (string) $variant->sku, $variant->cost_price ?? $product->cost_price, (string) $variant->final_price];
                }
            } else {
                $items[] = [null, $product->sku, $product->cost_price, (string) $product->final_price];
            }

            foreach ($items as [$variantId, $sku, $cost, $retail]) {
                $existing = $tiers[$product->id.':'.($variantId ?? 0)] ?? [];
                $before = $existing[1] ?? null;
                [$after, $skipped] = $this->newPrice($operation, $number, Money::fromRubles($retail), $cost !== null ? Money::fromRubles((string) $cost) : null);

                if ($skipped === null && $after !== null) {
                    foreach ($existing as $minQty => $tierPrice) {
                        if ($minQty >= 2 && $tierPrice > $after) {
                            $skipped = 'tier_conflict';
                            break;
                        }
                    }
                }

                $plan[] = [
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'name' => $product->name,
                    'sku' => $sku,
                    'before' => $before,
                    'after' => $skipped === null ? $after : $before,
                    'skipped' => $skipped,
                ];
            }
        }

        return $plan;
    }

    /**
     * @return array{int|null, string|null} the new exact price in kopecks (null: delete) and a skip reason
     */
    private function newPrice(GroupPriceBulkOperation $operation, ?string $number, int $retail, ?int $cost): array
    {
        if ($operation === GroupPriceBulkOperation::Delete) {
            return [null, null];
        }

        $number = (string) $number;

        if ($operation === GroupPriceBulkOperation::RetailMinusPercent) {
            $new = $this->scale($retail, bcsub('100', $number, 4));
        } else {
            if ($cost === null || $cost <= 0) {
                return [null, 'no_cost'];
            }

            $new = $this->scale($cost, bcadd('100', $number, 4));
        }

        return $new > 0 && $new <= 999_999_999_999 ? [$new, null] : [null, 'invalid_price'];
    }

    /**
     * round_half_up(kopecks * factor / 100) with bcmath.
     */
    private function scale(int $kopecks, string $factor): int
    {
        return (int) bcadd(bcdiv(bcmul((string) $kopecks, $factor, 4), '100', 6), '0.5', 0);
    }

    private function parseValue(GroupPriceBulkOperation $operation, ?string $value): ?string
    {
        if (! $operation->needsValue()) {
            return null;
        }

        $number = PositiveAmount::normalize($value);

        if ($number === null || bccomp($number, '0', 6) <= 0) {
            throw new InvalidArgumentException('The value must be a positive number.');
        }

        if ($operation === GroupPriceBulkOperation::RetailMinusPercent && bccomp($number, '100', 6) >= 0) {
            throw new InvalidArgumentException('The discount must be below 100 %.');
        }

        return bcadd($number, '0', 4);
    }
}
