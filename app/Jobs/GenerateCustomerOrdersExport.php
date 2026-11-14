<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Export;
use App\Services\Export\CustomerExportService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Builds a large order history export of a customer in the background (CX-04, CX-06): one job per export, within the
 * 40 seconds of TZ section 2; a timeout fails the job at once (no retry of a long run). At most one PDF job per customer
 * runs at a time (TZ 18.8): a second one waits and is released back to the queue.
 */
class GenerateCustomerOrdersExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 40;

    public bool $failOnTimeout = true;

    /** Unlimited attempts; the waiting for the PDF lock is bounded by {@see retryUntil()}. */
    public int $tries = 0;

    public function __construct(public int $exportId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $export = Export::query()->find($this->exportId, ['id', 'customer_id', 'type']);

        if ($export === null || $export->type !== ExportType::CustomerOrdersPdf) {
            return [];
        }

        return [(new WithoutOverlapping('customer-export-pdf-'.$export->customer_id))->releaseAfter(15)->expireAfter(120)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(CustomerExportService $service): void
    {
        $export = Export::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $service->generate($export);
    }

    public function failed(Throwable $exception): void
    {
        $export = Export::query()->find($this->exportId);

        // generate() records its own failures; this covers a timeout and a crash of the worker.
        if ($export !== null && $export->status !== ExportStatus::Failed && $export->status !== ExportStatus::Completed) {
            report($exception);
            app(CustomerExportService::class)->markFailed($export, __('customer_export.errors.generation_failed'));
        }
    }
}
