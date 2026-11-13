<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CustomerGroup;
use App\Services\Pricing\PriceListBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds the wholesale price list of one customer group (CG-17). A build is a chain of these jobs sharing a `run` id:
 * each reads products for up to {@see PriceListBuilder::STEP_SECONDS} seconds and queues the next one with the cursor,
 * the last one (cursor {@see PriceListBuilder::WRITE}) writes the Excel file, so every job stays under 40 s.
 */
class BuildPriceList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public int $groupId, public string $run, public int $cursor = 0) {}

    public function handle(PriceListBuilder $builder): void
    {
        $group = CustomerGroup::query()->find($this->groupId);

        if ($group === null || ! $builder->isEligible($group)) {
            return;
        }

        if ($this->cursor === PriceListBuilder::WRITE) {
            $builder->finish($group, $this->run);

            return;
        }

        if ($this->cursor === 0) {
            $builder->begin($group, $this->run);
        }

        $next = $builder->step($group, $this->run, $this->cursor, microtime(true) + PriceListBuilder::STEP_SECONDS);

        self::dispatch($this->groupId, $this->run, $next ?? PriceListBuilder::WRITE);
    }
}
