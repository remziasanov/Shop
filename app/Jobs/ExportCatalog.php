<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Services\Export\ExportManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds a large catalog export in the background (EX-03). The whole file is written in one go (streaming,
 * chunks of 500 products), see D-039 for the measured time.
 */
class ExportCatalog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public int $exportId) {}

    public function handle(ExportManager $manager): void
    {
        $export = Export::query()->find($this->exportId);

        if ($export === null || $export->status === ExportStatus::Completed) {
            return;
        }

        $manager->generate($export);
    }

    public function failed(Throwable $exception): void
    {
        $export = Export::query()->find($this->exportId);

        // generate() already marks and reports its own failures; this covers timeouts and worker crashes.
        if ($export !== null && $export->status !== ExportStatus::Failed && $export->status !== ExportStatus::Completed) {
            app(ExportManager::class)->markFailed($export, $exception->getMessage());
        }
    }
}
