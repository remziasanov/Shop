<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Import;
use App\Services\Import\ImportPhotoProcessor;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Shared body of the import queue jobs (TZ section 8: a job takes at most 40 s).
 *
 * Calls {@see ImportRunner::step()} while it makes progress and the {@see BUDGET} lasts (a chunk is ~1–3 s,
 * photo downloads and image attachments are bound by the same deadline, so the job ends at about 30 s + one image
 * and stays under 40 s),
 * then re-dispatches itself while work remains — a delayed one when the runner is busy elsewhere. With the sync
 * queue driver (tests) a waiting import is not re-dispatched, to avoid endless recursion.
 */
abstract class ProcessesImportSteps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds after which no new unit of work is started and no photo download or attachment begins (D-040). */
    public const BUDGET = 30.0;

    public int $tries = 3;

    public int $timeout = 40;

    public function __construct(public int $importId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ImportRunner $runner): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $started = microtime(true);

        do {
            $step = $runner->step($import, max(1.0, self::BUDGET - (microtime(true) - $started)));
        } while ($step->progressed() && self::BUDGET - (microtime(true) - $started) >= ImportPhotoProcessor::ATTACH_RESERVE_SECONDS);

        if ($step === ImportStep::Finished || $step === ImportStep::Stopped) {
            return;
        }

        $waiting = ! $step->progressed();

        if ($waiting && config('queue.default') === 'sync') {
            return;
        }

        $next = $runner->hasUnclaimedWork($import) ? ProcessImportChunk::dispatch($this->importId) : ProcessImportPhotos::dispatch($this->importId);

        if ($waiting) {
            $next->delay(now()->addSeconds(3));
        }
    }

    /**
     * Retries are exhausted: the import cannot continue.
     */
    public function failed(?Throwable $exception): void
    {
        $import = Import::query()->find($this->importId);

        if ($import !== null && $exception !== null) {
            app(ImportRunner::class)->fail($import, $exception);
        }
    }
}
