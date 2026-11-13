<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pricing\PriceListBuilder;
use Illuminate\Console\Command;

/**
 * CG-17: queues one price list build for every group whose «Оптовый прайс-лист» section is on (nightly 04:30). With
 * `--sync` the files are built at once, without the queue (small shops, diagnostics).
 */
class BuildPriceListsCommand extends Command
{
    protected $signature = 'shop:build-price-lists {--sync : Build the files now instead of queueing jobs}';

    protected $description = 'Queue the wholesale price list builds of the customer groups (CG-17)';

    public function handle(PriceListBuilder $builder): int
    {
        $groups = $builder->eligibleGroups();

        if ($groups->isEmpty()) {
            $this->info(__('group_prices.price_list.command.none'));

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            foreach ($groups as $group) {
                $builder->buildNow($group);
            }

            $this->info(__('group_prices.price_list.command.done_sync', ['count' => $groups->count()]));

            return self::SUCCESS;
        }

        $this->info(__('group_prices.price_list.command.queued', ['count' => $builder->queueAll()]));

        return self::SUCCESS;
    }
}
