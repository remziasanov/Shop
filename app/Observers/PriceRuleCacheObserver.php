<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\PriceRule;
use App\Models\PriceRuleTarget;
use App\Services\Pricing\PriceRuleRepository;

/**
 * Drops the cached rule set whenever a price rule or one of its targets changes.
 */
final class PriceRuleCacheObserver
{
    public function __construct(private readonly PriceRuleRepository $rules) {}

    public function saved(PriceRule|PriceRuleTarget $model): void
    {
        $this->rules->flush();
    }

    public function deleted(PriceRule|PriceRuleTarget $model): void
    {
        $this->rules->flush();
    }
}
