<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PromoBatchStatus;
use App\Models\PromoCodeBatch;
use App\Services\Promo\PromoBatchGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Generates the codes of a batch in chunks of 500 (PM-15). Runs at most ~30 seconds and queues itself again when the
 * batch is not complete (every job stays under the 40 s limit of the shared hosting); safe to run again at any time.
 */
class GeneratePromoCodeBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    /** Seconds of work per job before it hands over to the next one. */
    private const BUDGET = 30;

    public function __construct(public int $batchId) {}

    public function handle(PromoBatchGenerator $generator): void
    {
        $batch = PromoCodeBatch::query()->find($this->batchId);

        if ($batch === null) {
            return;
        }

        if (! $generator->run($batch, microtime(true) + self::BUDGET)) {
            self::dispatch($this->batchId);
        }
    }

    public function failed(Throwable $exception): void
    {
        PromoCodeBatch::query()->whereKey($this->batchId)->update(['status' => PromoBatchStatus::Failed->value]);
    }
}
