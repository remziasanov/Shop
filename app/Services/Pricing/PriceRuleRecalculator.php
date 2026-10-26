<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Jobs\RecalculatePrices;

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

        $this->dispatch($this->matcher->affectedIds($before, $after));
    }

    /**
     * Queue recalculation of the given products in chunks of {@see RecalculatePrices::MAX_IDS}.
     *
     * @param  iterable<int>  $productIds
     * @return array{products: int, jobs: int}
     */
    public function dispatch(iterable $productIds): array
    {
        $ids = [];

        foreach ($productIds as $id) {
            $ids[$id] = $id;
        }

        $chunks = array_chunk(array_values($ids), RecalculatePrices::MAX_IDS);

        if ($chunks === []) {
            return ['products' => 0, 'jobs' => 0];
        }

        $this->progress->add(count($ids));

        foreach ($chunks as $chunk) {
            RecalculatePrices::dispatch($chunk)->afterCommit();
        }

        return ['products' => count($ids), 'jobs' => count($chunks)];
    }
}
