<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Recalculates prices after a rule was created, changed, toggled or deleted (PR-02, D-027).
 *
 * Synchronous placeholder: the affected products are recalculated right away. A later stage replaces the
 * body with queued chunked jobs and progress tracking; the signature stays.
 */
class PriceRuleRecalculator
{
    public function __construct(
        private readonly RuleProductMatcher $matcher,
        private readonly ProductPriceUpdater $prices,
        private readonly PriceRuleRepository $rules,
    ) {}

    /**
     * @param  PriceRuleData|null  $before  the rule as it was before the change (null: created)
     * @param  PriceRuleData|null  $after  the rule as it is now (null: deleted)
     */
    public function ruleChanged(?PriceRuleData $before, ?PriceRuleData $after): void
    {
        $this->rules->flush();

        $this->prices->recalculate($this->matcher->affectedIds($before, $after));
    }
}
