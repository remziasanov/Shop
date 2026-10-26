<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Import;

/**
 * Presentation helpers of a running or finished import: browser fallback condition (IM-10), progress payload for
 * the fallback endpoint, duration label (IM-12, IM-13).
 */
final class ImportProgress
{
    /** Seconds without any activity after which the browser fallback is offered (IM-10). */
    public const FALLBACK_AFTER_SECONDS = 60;

    /**
     * The queue does not move the import: it is queued/processing and nothing was claimed or finished for
     * {@see FALLBACK_AFTER_SECONDS} (the start, a chunk claim and a finished chunk all touch `last_activity_at`).
     */
    public function needsBrowserFallback(Import $import): bool
    {
        if (! in_array($import->status->value, ['queued', 'processing'], true)) {
            return false;
        }

        $reference = $import->last_activity_at ?? $import->started_at;

        return $reference !== null && $reference->lte(now()->subSeconds(self::FALLBACK_AFTER_SECONDS));
    }

    /**
     * @return array{status: string, status_label: string, percent: int, rows_processed: int, rows_total: int, chunks_done: int, chunks_total: int, finished: bool}
     */
    public function payload(Import $import): array
    {
        return [
            'status' => $import->status->value,
            'status_label' => $import->status->label(),
            'percent' => $import->progressPercent(),
            'rows_processed' => $import->rows_processed,
            'rows_total' => $import->rows_total,
            'chunks_done' => $import->chunks_done,
            'chunks_total' => $import->chunks_total,
            'finished' => $import->status->isFinished(),
        ];
    }

    /**
     * Seconds between start and finish (or now while it runs); null when the import has not started.
     */
    public function durationSeconds(Import $import): ?int
    {
        if ($import->started_at === null) {
            return null;
        }

        $end = $import->finished_at ?? now();

        return max(0, (int) $import->started_at->diffInSeconds($end, true));
    }

    public function durationLabel(Import $import): string
    {
        $seconds = $this->durationSeconds($import);

        if ($seconds === null) {
            return '—';
        }

        if ($seconds < 60) {
            return __('import_ui.duration.seconds', ['n' => $seconds]);
        }

        if ($seconds < 3600) {
            return __('import_ui.duration.minutes', ['m' => intdiv($seconds, 60), 's' => $seconds % 60]);
        }

        return __('import_ui.duration.hours', ['h' => intdiv($seconds, 3600), 'm' => intdiv($seconds % 3600, 60)]);
    }
}
