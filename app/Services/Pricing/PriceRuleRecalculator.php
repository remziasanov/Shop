<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Jobs\RecalculatePrices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates prices after a rule was created, changed, toggled or deleted, or when a rule period boundary
 * passed (PR-02, D-027). Products are queued as {@see RecalculatePrices} jobs of up to 500 ids, with the total
 * registered in {@see RecalculationProgress} for the admin banner. With the sync queue driver (tests) the
 * jobs run inline.
 */
class PriceRuleRecalculator
{
    public function __construct(
        private readonly RuleProductMatcher $matcher,
        private readonly PriceRuleRepository $rules,
        private readonly RecalculationProgress $progress,
    ) {}

    /**
     * @param  PriceRuleData|null  $before  the rule as it was before the change (null: created)
     * @param  PriceRuleData|null  $after  the rule as it is now (null: deleted)
     */
    public function ruleChanged(?PriceRuleData $before, ?PriceRuleData $after): void
    {
        $this->rules->flush();

        // A rule bound to groups never touches the retail price (GP-04): only the groups of the rule are recalculated.
        // Binding a rule to groups or unbinding it changes the retail price too.
        $sides = array_values(array_filter([$before, $after]));
        $groupOnly = $sides !== [] && array_reduce($sides, fn (bool $carry, PriceRuleData $rule): bool => $carry && $rule->groupIds !== [], true);
        $groupIds = [];

        foreach ($sides as $rule) {
            $groupIds = array_merge($groupIds, $rule->groupIds);
        }

        $this->dispatch($this->matcher->affectedIds($before, $after), $groupOnly ? array_values(array_unique($groupIds)) : null);
    }

    /**
     * Queue recalculation of the prices of the given customer groups for every live product (a group discount changed,
     * GP-07). The retail prices are not touched.
     *
     * @param  list<int>  $groupIds
     * @return array{products: int, jobs: int}
     */
    public function dispatchForGroups(array $groupIds): array
    {
        if ($groupIds === []) {
            return ['products' => 0, 'jobs' => 0];
        }

        return $this->dispatch($this->allIds(), $groupIds);
    }

    /**
     * Ids of all live (not soft-deleted) products, ascending.
     *
     * @return Collection<int, int>
     */
    public function allIds(): Collection
    {
        return DB::table('products')->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id);
    }

    /**
     * Queue recalculation of every live product (what `shop:recalc-prices --all` does), e.g. after the pricing
     * settings changed.
     *
     * @return array{products: int, jobs: int}
     */
    public function dispatchAll(): array
    {
        $this->rules->flush();

        return $this->dispatch($this->allIds());
    }

    /**
     * Queue recalculation of the live products of the given categories (their rule matching may have changed).
     *
     * @param  list<int>  $categoryIds
     * @return array{products: int, jobs: int}
     */
    public function dispatchForCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return ['products' => 0, 'jobs' => 0];
        }

        $ids = DB::table('products')
            ->whereNull('deleted_at')
            ->whereIn('category_id', $categoryIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        return $this->dispatch($ids);
    }

    /**
     * Queue recalculation of the given products in jobs of up to {@see RecalculatePrices::MAX_IDS} priced entities: a
     * product without variants counts as one, a card with variants as many as it has live variants (VR-07, 19.6), so a
     * job stays within the 40 second limit. A card heavier than a whole job gets a job of its own.
     *
     * @param  iterable<int>  $productIds
     * @param  list<int>|null  $groupIds  null: retail and all groups; a list: only the prices of these groups
     * @return array{products: int, jobs: int}
     */
    public function dispatch(iterable $productIds, ?array $groupIds = null): array
    {
        $ids = [];

        foreach ($productIds as $id) {
            $ids[$id] = $id;
        }

        $chunks = $this->chunks(array_values($ids));

        if ($chunks === []) {
            return ['products' => 0, 'jobs' => 0];
        }

        $this->progress->add(count($ids));

        foreach ($chunks as $chunk) {
            RecalculatePrices::dispatch($chunk, $groupIds)->afterCommit();
        }

        return ['products' => count($ids), 'jobs' => count($chunks)];
    }

    /**
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    private function chunks(array $ids): array
    {
        $weights = [];

        foreach (array_chunk($ids, 1000) as $part) {
            $rows = DB::table('product_variants')
                ->whereIn('product_id', $part)
                ->whereNull('deleted_at')
                ->groupBy('product_id')
                ->selectRaw('product_id, COUNT(*) as variants')
                ->get();

            foreach ($rows as $row) {
                $weights[(int) $row->product_id] = (int) $row->variants;
            }
        }

        $chunks = [];
        $current = [];
        $weight = 0;

        foreach ($ids as $id) {
            $own = max(1, $weights[$id] ?? 1);

            if ($current !== [] && ($weight + $own > RecalculatePrices::MAX_IDS || count($current) >= RecalculatePrices::MAX_IDS)) {
                $chunks[] = $current;
                $current = [];
                $weight = 0;
            }

            $current[] = $id;
            $weight += $own;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
