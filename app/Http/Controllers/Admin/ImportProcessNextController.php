<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Import;
use App\Policies\ImportPolicy;
use App\Services\Import\ImportProgress;
use App\Services\Import\ImportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Browser fallback of the import queue (IM-10): the import page calls this endpoint in a loop while the tab is open;
 * every call does one unit of work and must end within 20 s: the runner gets a hard deadline of
 * {@see ImportRunner::BROWSER_BUDGET} seconds (D-040). Panel session, CSRF (web group) and {@see ImportPolicy}.
 *
 * An exception does not fail the import at once (the queue gives a job 3 tries as well): the chunk is rolled back and
 * released, the client gets 503 with `retry: true` and calls again with a growing pause; only the
 * {@see MAX_FAILURES}-th failure in a row stops the import.
 */
final class ImportProcessNextController
{
    public const MAX_FAILURES = 3;

    public function __invoke(Import $import, ImportRunner $runner, ImportProgress $progress): JsonResponse
    {
        Gate::authorize('process', $import);

        $key = 'import:'.$import->id.':browser_failures';

        try {
            $more = $runner->processNext($import, ImportRunner::BROWSER_BUDGET);
            Cache::forget($key);
        } catch (Throwable $e) {
            Cache::add($key, 0, 3600);
            $failures = (int) Cache::increment($key);

            if ($failures < self::MAX_FAILURES) {
                report($e);

                return response()->json([
                    'more' => true,
                    'retry' => true,
                    'error' => __('import_ui.process_endpoint.retry'),
                    'progress' => $progress->payload($import->refresh()),
                ], 503);
            }

            Cache::forget($key);
            $runner->fail($import, $e);

            return response()->json([
                'more' => false,
                'error' => __('import_ui.process_endpoint.failed'),
                'progress' => $progress->payload($import->refresh()),
            ], 500);
        }

        return response()->json([
            'more' => $more,
            'progress' => $progress->payload($import->refresh()),
        ]);
    }
}
