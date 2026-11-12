<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Variants\VariantAggregator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily reconciliation of the card aggregates over their variants (VR-06, 19.5): stock, reserved quantity and prices.
 * Every discrepancy that is found is written to the log and fixed.
 */
class VariantsRollupCommand extends Command
{
    /** Stop after this many seconds; the rest waits for the next run (TZ section 2). */
    public const BUDGET_SECONDS = 40;

    protected $signature = 'shop:variants-rollup';

    protected $description = 'Recompute the stock and price aggregates of cards with variants and log discrepancies (VR-06)';

    public function handle(VariantAggregator $aggregator): int
    {
        $fixed = $aggregator->rollupAll(microtime(true) + self::BUDGET_SECONDS);

        foreach ($fixed as $row) {
            Log::warning('Variant aggregate discrepancy fixed', $row);
        }

        $this->info(__('variants.rollup.done', ['count' => count($fixed)]));

        return self::SUCCESS;
    }
}
