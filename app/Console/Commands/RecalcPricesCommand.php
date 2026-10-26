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
use Illuminate\Support\Facades\Date;

/**
 * Scheduled price recalculation (PR-02, TZ section 8): `--boundaries` every 15 minutes picks up rules whose
 * start or end passed since the previous run, `--all` rebuilds every product nightly.
 */
class RecalcPricesCommand extends Command
{
    /** Last `--boundaries` run: a file (like the heartbeat), so that clearing the cache on deploy does not lose it. */
    public static function lastRunPath(): string
    {
        return storage_path('app/pricing-boundaries-last-run');
    }

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

        if ($this->option('sync')) {
            $ids = $all ? $recalculator->allIds() : $this->boundaryIds($matcher, $now);
            $count = $ids->count();

            foreach ($ids->chunk(RecalculatePrices::MAX_IDS) as $chunk) {
                $prices->recalculate($chunk->all());
            }

            $this->info(__('pricing.recalc_command.done_sync', ['products' => $count]));
        } else {
            $result = $all ? $recalculator->dispatchAll() : $recalculator->dispatch($this->boundaryIds($matcher, $now));

            $this->info(__('pricing.recalc_command.queued', $result));
        }

        if ($boundaries) {
            $this->storeLastRun($now->getTimestamp());
        }

        return self::SUCCESS;
    }

    private function storeLastRun(int $timestamp): void
    {
        $path = self::lastRunPath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, (string) $timestamp, LOCK_EX);
    }

    private function readLastRun(): ?string
    {
        $path = self::lastRunPath();

        return is_file($path) ? trim((string) file_get_contents($path)) : null;
    }

    /**
     * @return Collection<int, int>
     */
    private function boundaryIds(RuleProductMatcher $matcher, CarbonInterface $now): Collection
    {
        $stored = $this->readLastRun();
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
