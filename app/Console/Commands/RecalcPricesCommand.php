<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecalculatePrices;
use App\Models\PriceRule;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Pricing\PriceRuleRepository;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Pricing\RuleProductMatcher;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled price recalculation (PR-02, TZ section 8): `--boundaries` every 15 minutes picks up rules whose
 * start or end passed since the previous run, `--all` rebuilds every product nightly.
 */
class RecalcPricesCommand extends Command
{
    public const LAST_RUN_KEY = 'pricing.boundaries.last_run';

    /** Default window when there was no previous run (a bit more than the 15 minute schedule). */
    private const DEFAULT_WINDOW_MINUTES = 16;

    protected $signature = 'shop:recalc-prices
        {--all : Recalculate all products}
        {--boundaries : Recalculate products of rules that started or ended since the last run}
        {--sync : Recalculate right away instead of queueing jobs}';

    protected $description = 'Recalculate product prices in the background (PR-02)';

    public function handle(
        PriceRuleRecalculator $recalculator,
        PriceRuleRepository $repository,
        RuleProductMatcher $matcher,
        ProductPriceUpdater $prices,
    ): int {
        $all = (bool) $this->option('all');
        $boundaries = (bool) $this->option('boundaries');

        if ($all === $boundaries) {
            $this->error(__('pricing.recalc_command.choose_one'));

            return self::INVALID;
        }

        $repository->flush();
        $now = now();

        if ($all) {
            $ids = DB::table('products')->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id);
        } else {
            $ids = $this->boundaryIds($matcher, $now);
        }

        if ($this->option('sync')) {
            $count = $ids->count();

            foreach ($ids->chunk(RecalculatePrices::MAX_IDS) as $chunk) {
                $prices->recalculate($chunk->all());
            }

            $this->info(__('pricing.recalc_command.done_sync', ['products' => $count]));
        } else {
            $result = $recalculator->dispatch($ids);

            $this->info(__('pricing.recalc_command.queued', $result));
        }

        if ($boundaries) {
            Cache::forever(self::LAST_RUN_KEY, $now->getTimestamp());
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    private function boundaryIds(RuleProductMatcher $matcher, CarbonInterface $now): Collection
    {
        $stored = Cache::get(self::LAST_RUN_KEY);
        $lastRun = is_numeric($stored)
            ? Date::createFromTimestamp((int) $stored, $now->getTimezone())
            : $now->copy()->subMinutes(self::DEFAULT_WINDOW_MINUTES);

        $rules = PriceRule::query()
            ->active()
            ->where(function ($query) use ($lastRun, $now): void {
                $query->where(fn ($q) => $q->where('starts_at', '>', $lastRun)->where('starts_at', '<=', $now))
                    ->orWhere(fn ($q) => $q->where('ends_at', '>', $lastRun)->where('ends_at', '<=', $now));
            })
            ->with('targets')
            ->get();

        $ids = [];

        foreach ($rules as $rule) {
            foreach ($matcher->productIds(PriceRuleData::fromModel($rule)) as $id) {
                $ids[$id] = $id;
            }
        }

        ksort($ids);

        return collect(array_values($ids));
    }
}
