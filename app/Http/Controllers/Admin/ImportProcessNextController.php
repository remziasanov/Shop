<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Import;
use App\Policies\ImportPolicy;
use App\Services\Import\ImportProgress;
use App\Services\Import\ImportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Browser fallback of the import queue (IM-10): the import page calls this endpoint in a loop while the tab is open;
 * every call does one unit of work of at most ~20 s. Panel session, CSRF (web group) and {@see ImportPolicy}.
 */
final class ImportProcessNextController
{
    public function __invoke(Import $import, ImportRunner $runner, ImportProgress $progress): JsonResponse
    {
        Gate::authorize('process', $import);

        try {
            $more = $runner->processNext($import, 20.0);
        } catch (Throwable $e) {
            // A chunk that fails here would fail the same way in the queue: stop the import with a safe message.
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
