<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Exceptions\ImportFileException;
use App\Models\Import;
use App\Services\Import\ImportAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Streams the uploaded file once into the staging files and computes the preview and summary (D-033 step 3).
 *
 * The pass is linear: about 0.5 s per 10 000 CSV rows locally; an .xlsx of the same size takes a few seconds, so
 * the realistic upper bound for the 20 000-product catalog (NF-01) stays far below the 40 s job limit. The analyzer
 * marks the import `failed` itself; a failed analysis is deterministic, so the job is not retried.
 */
class AnalyzeImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public int $importId) {}

    public function handle(ImportAnalyzer $analyzer): void
    {
        $import = Import::query()->find($this->importId);

        // Already analyzed in the browser (fallback) or started: never re-stage the rows under a running import.
        if ($import === null || ! in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Analyzing], true)) {
            return;
        }

        try {
            $analyzer->analyze($import);
        } catch (ImportFileException) {
            // Already stored on the import as a user-facing message.
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Timeout or crash: the import must not stay in "analyzing" forever.
     */
    public function failed(?Throwable $exception): void
    {
        Import::query()
            ->whereKey($this->importId)
            ->where('status', 'analyzing')
            ->update(['status' => 'failed', 'failure_message' => __('import.errors.analysis_failed'), 'finished_at' => now()]);
    }
}
