<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Exceptions\ImportFileException;
use App\Http\Controllers\Admin\ImportProcessNextController;
use App\Jobs\ProcessImportChunk;
use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Users\PermissionService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/**
 * Drives an import through its stages (D-033): categories sheet, product chunks, photos, finish.
 *
 * Entry points for the UI and the queue:
 *  - {@see start()}: analyzed → queued, dispatches the {@see ProcessImportChunk} chain;
 *  - {@see processNext()} / {@see step()}: one unit of work (the categories sheet, one chunk of 200 rows with its
 *    photos, one file of pending photos, or the final step). The queue job and the browser fallback (IM-10) both
 *    call it; a chunk is claimed with a conditional UPDATE ({@see claimNextChunk()}) and the data step runs under
 *    a short cache lock, so concurrent callers can never apply a chunk twice and chunks are applied in order.
 *    The photo tasks of a chunk are written to `imports/{id}/photos-working-{label}.json` BEFORE the lock is
 *    released (D-040), so a concurrent caller always sees that work is in progress and never finishes the import
 *    under it; the file is touched (and `last_activity_at` updated) after every photo, deleted when the tasks are
 *    done and renamed to `photos-pending-{label}.json` when the time budget ends first. A working file untouched
 *    for {@see STALE_CLAIM_SECONDS} belongs to a dead worker and goes back to pending;
 *  - {@see finish()}: runs once when all chunks and photos are done — full-sync deactivation, price recalculation
 *    of touched products (IM-14), cache flush, {@see ImportFinished}, final status, bell notification (section 9);
 *  - {@see fail()}: marks the import failed with a safe message;
 *  - {@see fullSyncAffectedCount()}: how many active products a full sync would deactivate (IM-04 confirmation).
 *
 * Files (private `local` disk, `imports/{id}/`): `touched.txt` ids of created/updated products,
 * `photos-pending-*.json`, `photos-working-*.json`, `downloads/`, `media/` (the last two are removed on finish).
 */
final class ImportRunner
{
    /** Seconds a processing lock lives; a crashed worker blocks the import at most this long. */
    public const LOCK_TTL = 60;

    /**
     * A claimed chunk without progress for this long is considered lost and is claimed again; a photo working file
     * that was not touched for this long belongs to a dead worker and is returned to the pending list.
     */
    public const STALE_CLAIM_SECONDS = 120;

    /** Time budget of one browser fallback request (IM-10: ≤ 20 s per request, the rest is the safety margin). */
    public const BROWSER_BUDGET = 18.0;

    private const FINISHED = ['completed', 'completed_with_errors', 'failed', 'cancelled'];

    public function __construct(
        private readonly RowImporter $rows,
        private readonly CategorySheetImporter $categorySheet,
        private readonly ImportPhotoProcessor $photos,
        private readonly PriceRuleRecalculator $prices,
        private readonly CategoryTree $tree,
    ) {}

    /**
     * Queue an analyzed import: status `queued`, progress counters reset, first job dispatched.
     *
     * With an acting user the «full synchronization» mode needs the `imports.full_sync` right (RB-11).
     *
     * @throws LogicException when the import is not in the `analyzed` state
     * @throws AuthorizationException when the actor may not run this import
     */
    public function start(Import $import, ?User $actor = null): Import
    {
        if ($actor !== null) {
            $permissions = app(PermissionService::class);

            if (! $permissions->allows($actor, 'imports.run') || ($import->mode === ImportMode::FullSync && ! $permissions->allows($actor, 'imports.full_sync'))) {
                throw new AuthorizationException;
            }
        }

        $started = DB::table('imports')
            ->where('id', $import->id)
            ->where('status', ImportStatus::Analyzed->value)
            ->update([
                'status' => ImportStatus::Queued->value,
                'started_at' => now(),
                'finished_at' => null,
                'failure_message' => null,
                'next_chunk' => 0,
                'chunks_done' => 0,
                'rows_processed' => 0,
                'created_count' => 0,
                'updated_count' => 0,
                'unchanged_count' => 0,
                'skipped_count' => 0,
                'error_count' => 0,
                'warning_count' => 0,
                'last_activity_at' => now(),
            ]);

        if ($started === 0) {
            throw new LogicException('Only an analyzed import can be started.');
        }

        $this->deleteWorkFiles($import);

        ProcessImportChunk::dispatch($import->id);

        return $import->refresh();
    }

    /**
     * Atomically take the next unprocessed chunk (D-033): `UPDATE imports SET next_chunk = n + 1 WHERE next_chunk = n`.
     *
     * @return int|null chunk index, null when every chunk is claimed (or the import is finished)
     */
    public function claimNextChunk(Import $import): ?int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = DB::table('imports')->where('id', $import->id)->first(['next_chunk', 'chunks_total']);

            if ($row === null || (int) $row->next_chunk >= (int) $row->chunks_total) {
                return null;
            }

            $claimed = DB::table('imports')
                ->where('id', $import->id)
                ->where('next_chunk', $row->next_chunk)
                ->whereNotIn('status', self::FINISHED)
                ->update(['next_chunk' => DB::raw('next_chunk + 1'), 'last_activity_at' => now()]);

            if ($claimed === 1) {
                return (int) $row->next_chunk;
            }

            if (DB::table('imports')->where('id', $import->id)->whereIn('status', self::FINISHED)->exists()) {
                return null;
            }
        }

        return null;
    }

    /**
     * Do one unit of work within roughly $timeBudgetSeconds (no new photo download starts after it).
     * Exceptions of the data step propagate (the chunk is rolled back and can be claimed again); callers decide
     * whether to retry or call {@see fail()}.
     *
     * @return bool true while the import has more work (call again, possibly after a short wait); false when it is
     *              finished or not running
     */
    public function processNext(Import $import, float $timeBudgetSeconds = 20.0): bool
    {
        $step = $this->step($import, $timeBudgetSeconds);

        return $step !== ImportStep::Finished && $step !== ImportStep::Stopped;
    }

    /**
     * Cache key of the consecutive failures of the browser fallback ({@see ImportProcessNextController}).
     */
    public static function browserFailuresKey(Import $import): string
    {
        return 'import:'.$import->id.':browser_failures';
    }

    public function step(Import $import, float $timeBudgetSeconds = 20.0): ImportStep
    {
        $step = $this->runStep($import, $timeBudgetSeconds);

        // Any step that did work (queue or browser) ends a streak of browser failures: they must be consecutive.
        if (in_array($step, [ImportStep::Categories, ImportStep::Chunk, ImportStep::Photos, ImportStep::Finished], true)) {
            Cache::forget(self::browserFailuresKey($import));
        }

        return $step;
    }

    private function runStep(Import $import, float $timeBudgetSeconds): ImportStep
    {
        $deadline = microtime(true) + $timeBudgetSeconds;
        $import->refresh();

        if ($import->status->isFinished()) {
            return ImportStep::Finished;
        }

        if ($import->status !== ImportStatus::Queued && $import->status !== ImportStatus::Processing) {
            return ImportStep::Stopped;
        }

        $lock = Cache::lock($this->lockKey($import, 'run'), self::LOCK_TTL);

        if (! $lock->get()) {
            return ImportStep::Busy;
        }

        $step = null;
        $tasks = [];
        $label = '';
        $working = null;

        try {
            $import->refresh();

            if ($import->status->isFinished()) {
                return ImportStep::Finished;
            }

            if ($import->status === ImportStatus::Queued) {
                DB::table('imports')->where('id', $import->id)->where('status', ImportStatus::Queued->value)
                    ->update(['status' => ImportStatus::Processing->value, 'last_activity_at' => now()]);
                $import->refresh();
            }

            $this->recoverStaleClaim($import);

            if ($this->categoriesPending($import)) {
                $tasks = $this->runCategories($import);
                $step = ImportStep::Categories;
                $label = 'categories';
            } elseif (($index = $this->claimNextChunk($import)) !== null) {
                try {
                    $result = $this->rows->importChunk($import, $index);
                } catch (Throwable $e) {
                    $this->releaseClaim($import, $index);

                    throw $e;
                }

                $this->appendTouched($import, $result->touchedIds);
                $tasks = $result->photoTasks;
                $step = ImportStep::Chunk;
                $label = 'chunk-'.$index;
            }

            // Still under the lock: from here on the work is visible on disk to every other caller.
            $working = $this->persistTasks($import, $tasks, $label);
        } finally {
            $lock->release();
        }

        if ($step !== null) {
            if ($working !== null) {
                $this->processWorkingFile($import, $working, $deadline);
            }

            return $step;
        }

        $import->refresh();

        if ($import->chunks_done < $import->chunks_total) {
            return ImportStep::Waiting;
        }

        if ($this->processPendingPhotos($import, $deadline)) {
            return ImportStep::Photos;
        }

        if ($this->hasPhotoFiles($import)) {
            return ImportStep::Waiting;
        }

        return $this->finish($import) ? ImportStep::Finished : ImportStep::Waiting;
    }

    /**
     * Whether product chunks (or the categories sheet) still wait to be claimed.
     */
    public function hasUnclaimedWork(Import $import): bool
    {
        $import->refresh();

        return $this->categoriesPending($import) || $import->next_chunk < $import->chunks_total;
    }

    /**
     * Final step: full-sync deactivation, price recalculation of touched products, cache flush, event, status and
     * notification. Safe to call from several workers; does nothing until all chunks and photos are done.
     *
     * @return bool true when the import is finished (by this call or earlier)
     */
    public function finish(Import $import): bool
    {
        // The run lock is held by a worker between claiming a chunk and writing its photo tasks to disk: finishing
        // is only safe when nobody is in that window.
        $run = Cache::lock($this->lockKey($import, 'run'), self::LOCK_TTL);

        if (! $run->get()) {
            return false;
        }

        $lock = Cache::lock($this->lockKey($import, 'finish'), 300);

        if (! $lock->get()) {
            $run->release();

            return false;
        }

        try {
            $import->refresh();

            if ($import->status->isFinished()) {
                return true;
            }

            if ($import->chunks_done < $import->chunks_total || $this->hasPhotoFiles($import)) {
                return false;
            }

            $analysis = $import->analysis ?? [];
            $result = is_array($analysis['result'] ?? null) ? $analysis['result'] : [];

            if (! $import->importOptions()->dryRun) {
                if ($import->mode === ImportMode::FullSync) {
                    $result['deactivated'] = $this->deactivateMissing($import);
                }

                $this->prices->dispatch($this->touchedIds($import));
                $this->tree->flush();
            }

            $result['unchanged'] = $import->unchanged_count;
            $analysis['result'] = $result;
            $status = $import->error_count > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed;

            $done = DB::table('imports')
                ->where('id', $import->id)
                ->whereNotIn('status', self::FINISHED)
                ->update([
                    'status' => $status->value,
                    'finished_at' => now(),
                    'last_activity_at' => now(),
                    'analysis' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

            if ($done === 0) {
                return true;
            }

            $import->refresh();
            $this->deleteWorkFiles($import);
            event(new ImportFinished($import));
            $this->notify($import);

            return true;
        } finally {
            $lock->release();
            $run->release();
        }
    }

    /**
     * Mark the import failed with a message that is safe to show; the details go to the log.
     */
    public function fail(Import $import, Throwable $exception): void
    {
        report($exception);

        $message = $exception instanceof ImportFileException ? $exception->getMessage() : __('import_process.errors.failed');

        $done = DB::table('imports')
            ->where('id', $import->id)
            ->whereNotIn('status', self::FINISHED)
            ->update([
                'status' => ImportStatus::Failed->value,
                'failure_message' => $message,
                'finished_at' => now(),
                'last_activity_at' => now(),
            ]);

        if ($done === 0) {
            return;
        }

        $import->refresh();
        $this->deleteWorkFiles($import);
        event(new ImportFinished($import));
        $this->notify($import);
    }

    /**
     * Active, non-deleted products whose SKU is not in the file: what a full sync would deactivate (IM-04).
     * Available after the analysis (reads the staged rows).
     */
    public function fullSyncAffectedCount(Import $import): int
    {
        $skus = $this->fileSkus($import);

        if ($skus === []) {
            return 0;
        }

        $count = 0;

        Product::query()->where('is_active', true)->select(['id', 'sku'])->chunkById(1000, function ($products) use ($skus, &$count): void {
            foreach ($products as $product) {
                if (! isset($skus[mb_strtolower($product->sku, 'UTF-8')])) {
                    $count++;
                }
            }
        });

        return $count;
    }

    // ------------------------------------------------------------------

    private function lockKey(Import $import, string $name): string
    {
        return 'import:'.$import->id.':'.$name;
    }

    private function categoriesPending(Import $import): bool
    {
        return $import->categories_staging_path !== null && ! (bool) ($import->analysis['categories_done'] ?? false);
    }

    /**
     * Apply the categories sheet (own transaction) and remember it is done.
     *
     * @return list<array<string, mixed>> photo tasks
     */
    private function runCategories(Import $import): array
    {
        $resolver = new CategoryPathResolver;
        $result = $this->categorySheet->import($import, $resolver);

        $analysis = $import->analysis ?? [];
        $analysis['categories_done'] = true;
        $analysis['result'] = array_merge(is_array($analysis['result'] ?? null) ? $analysis['result'] : [], [
            'categories_created' => $resolver->createdCount(),
            'categories_updated' => $result->updated,
        ]);

        DB::table('imports')->where('id', $import->id)->update([
            'error_count' => DB::raw('error_count + '.$result->errors),
            'warning_count' => DB::raw('warning_count + '.$result->warnings),
            'analysis' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_activity_at' => now(),
        ]);

        return $result->photoTasks;
    }

    /**
     * A worker that died holding a claim leaves `next_chunk > chunks_done` with no activity: claim that chunk again.
     * Chunks run strictly one after another (the lock), so the lost chunk is the one at index `chunks_done`.
     */
    private function recoverStaleClaim(Import $import): void
    {
        $row = DB::table('imports')->where('id', $import->id)->first(['next_chunk', 'chunks_done', 'last_activity_at']);

        if ($row === null || (int) $row->next_chunk <= (int) $row->chunks_done || $row->last_activity_at === null) {
            return;
        }

        if (CarbonImmutable::parse((string) $row->last_activity_at)->addSeconds(self::STALE_CLAIM_SECONDS)->isFuture()) {
            return;
        }

        DB::table('imports')
            ->where('id', $import->id)
            ->where('next_chunk', $row->next_chunk)
            ->update(['next_chunk' => $row->chunks_done, 'last_activity_at' => now()]);
    }

    private function releaseClaim(Import $import, int $index): void
    {
        DB::table('imports')->where('id', $import->id)->where('next_chunk', $index + 1)->update(['next_chunk' => $index]);
    }

    // ------------------------------------------------------------------ photos

    /**
     * Write the photo tasks of a unit of work to `photos-working-{label}.json` (called under the run lock).
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return string|null absolute path of the working file, null when there is nothing to do
     */
    private function persistTasks(Import $import, array $tasks, string $label): ?string
    {
        if ($tasks === []) {
            return null;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory($this->importDir($import));
        $path = $disk->path($this->importDir($import).'/photos-working-'.$label.'.json');

        file_put_contents($path, json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);

        return $path;
    }

    /**
     * Run the tasks of a claimed working file. What does not fit into the time budget (or a crash with an exception)
     * goes back to `photos-pending-*.json`; a file that was taken over as stale in the meantime is left alone.
     */
    private function processWorkingFile(Import $import, string $working, float $deadline): bool
    {
        $pendingFile = dirname($working).'/photos-pending-'.substr(basename($working), strlen('photos-working-'));
        $decoded = json_decode((string) file_get_contents($working), true);
        $tasks = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];

        $heartbeat = function () use ($import, $working): void {
            @touch($working);
            DB::table('imports')->where('id', $import->id)->update(['last_activity_at' => now()]);
        };

        // On an exception the tasks that were already finished (counted, warnings written) must not run again on
        // the retry: the working file is cut down to the task that failed and the ones after it.
        $rewrite = function (array $remaining) use ($working): void {
            if (is_file($working)) {
                file_put_contents($working, json_encode(array_values($remaining), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
            }
        };
        $onAbort = $rewrite;

        try {
            $left = $this->photos->run($import, $tasks, $deadline, $heartbeat, $onAbort, $rewrite);
        } catch (Throwable $e) {
            $this->withPhotosLock($import, function () use ($working, $pendingFile): void {
                if (is_file($working)) {
                    @rename($working, $pendingFile);
                }
            }, true);

            throw $e;
        }

        $this->withPhotosLock($import, function () use ($working, $pendingFile, $left): void {
            if (! is_file($working)) {
                return;
            }

            if ($left === []) {
                @unlink($working);

                return;
            }

            file_put_contents($working, json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
            @rename($working, $pendingFile);
        }, true);

        return $this->photos->didWork();
    }

    /**
     * Run $callback under the per-import photo-file lock (`import:{id}:photos`): claiming a pending file, returning
     * a stale one and handing a file back must not interleave (D-033, no duplicate photo work).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  bool  $runAnyway  when the lock cannot be taken within a few seconds: run unlocked (true) or skip (false)
     * @return T|null null when skipped
     */
    private function withPhotosLock(Import $import, callable $callback, bool $runAnyway = false): mixed
    {
        $lock = Cache::lock($this->lockKey($import, 'photos'), self::LOCK_TTL);

        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            return $runAnyway ? $callback() : null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Process one file of pending photo tasks. The file is claimed by renaming it under the photo lock, so two
     * workers never take the same one.
     *
     * @return bool true when a file was taken and some of its work was done
     */
    private function processPendingPhotos(Import $import, float $deadline): bool
    {
        $directory = Storage::disk('local')->path($this->importDir($import));

        $working = $this->withPhotosLock($import, function () use ($directory, $deadline): ?string {
            // Return files abandoned by a dead worker (also when the budget is spent: finish() depends on it).
            clearstatcache();
            foreach (glob($directory.'/photos-working-*.json') ?: [] as $stale) {
                if (filemtime($stale) !== false && filemtime($stale) < time() - self::STALE_CLAIM_SECONDS) {
                    @rename($stale, $directory.'/photos-pending-'.substr(basename($stale), strlen('photos-working-')));
                }
            }

            // Not enough time left to attach even one image: leave the file for the next (delayed) step.
            if (microtime(true) + ImportPhotoProcessor::ATTACH_RESERVE_SECONDS >= $deadline) {
                return null;
            }

            foreach (glob($directory.'/photos-pending-*.json') ?: [] as $pendingFile) {
                $claimed = $directory.'/photos-working-'.substr(basename($pendingFile), strlen('photos-pending-'));

                if (is_file($claimed) || ! @rename($pendingFile, $claimed)) {
                    continue;
                }

                // The rename keeps the old modification time: without this a fresh claim would look stale.
                @touch($claimed);

                return $claimed;
            }

            return null;
        });

        if ($working === null) {
            return false;
        }

        // A file that was claimed but only deferred (no work done) is not progress.
        return $this->processWorkingFile($import, $working, $deadline);
    }

    private function hasPhotoFiles(Import $import): bool
    {
        $directory = Storage::disk('local')->path($this->importDir($import));

        return (glob($directory.'/photos-pending-*.json') ?: []) !== [] || (glob($directory.'/photos-working-*.json') ?: []) !== [];
    }

    // ------------------------------------------------------------------ files

    private function importDir(Import $import): string
    {
        return 'imports/'.$import->id;
    }

    /**
     * @param  list<int>  $ids
     */
    private function appendTouched(Import $import, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory($this->importDir($import));
        file_put_contents($disk->path($this->importDir($import).'/touched.txt'), implode("\n", $ids)."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<int>
     */
    private function touchedIds(Import $import): array
    {
        $path = Storage::disk('local')->path($this->importDir($import).'/touched.txt');

        if (! is_file($path)) {
            return [];
        }

        $ids = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $ids[(int) $line] = (int) $line;
        }

        return array_values($ids);
    }

    private function deleteWorkFiles(Import $import): void
    {
        MediaResolver::cleanup($import);

        $directory = Storage::disk('local')->path($this->importDir($import));

        foreach (['photos-pending-*.json', 'photos-working-*.json'] as $pattern) {
            foreach (glob($directory.'/'.$pattern) ?: [] as $file) {
                @unlink($file);
            }
        }

        Storage::disk('local')->delete($this->importDir($import).'/touched.txt');
    }

    // ------------------------------------------------------------------ full sync

    /**
     * Lower-cased SKUs of every staged row (rows with errors included: their products stay active).
     *
     * @return array<string, true>
     */
    private function fileSkus(Import $import): array
    {
        if ($import->staging_path === null) {
            return [];
        }

        $path = Storage::disk('local')->path($import->staging_path);
        $handle = is_file($path) ? fopen($path, 'rb') : false;

        if ($handle === false) {
            return [];
        }

        $skus = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode($line, true);
                $sku = is_array($decoded) ? ($decoded['values']['sku'] ?? null) : null;

                if (is_string($sku) && $sku !== '') {
                    $skus[mb_strtolower($sku, 'UTF-8')] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        return $skus;
    }

    private function deactivateMissing(Import $import): int
    {
        $skus = $this->fileSkus($import);

        // An empty file must never switch the whole catalog off.
        if ($skus === []) {
            return 0;
        }

        $count = 0;

        Product::query()->where('is_active', true)->select(['id', 'sku'])->chunkById(1000, function ($products) use ($skus, &$count): void {
            $ids = [];

            foreach ($products as $product) {
                if (! isset($skus[mb_strtolower($product->sku, 'UTF-8')])) {
                    $ids[] = $product->id;
                }
            }

            if ($ids !== []) {
                DB::table('products')->whereIn('id', $ids)->update(['is_active' => false, 'updated_at' => now()]);
                $count += count($ids);
            }
        });

        return $count;
    }

    // ------------------------------------------------------------------ notification

    private function notify(Import $import): void
    {
        try {
            $user = $import->user_id !== null ? User::query()->find($import->user_id) : null;

            if ($user === null) {
                return;
            }

            $suffix = $import->dry_run ? __('import_process.notification.dry_run_suffix') : '';

            if ($import->status === ImportStatus::Failed) {
                $notification = Notification::make()
                    ->title(__('import_process.notification.failed').$suffix)
                    ->body(__('import_process.notification.failed_body', ['file' => $import->original_name, 'message' => (string) $import->failure_message]))
                    ->danger();
            } else {
                $notification = Notification::make()
                    ->title(__('import_process.notification.'.$import->status->value).$suffix)
                    ->body(__('import_process.notification.summary', [
                        'file' => $import->original_name,
                        'created' => $import->created_count,
                        'updated' => $import->updated_count,
                        'unchanged' => $import->unchanged_count,
                        'skipped' => $import->skipped_count,
                        'errors' => $import->error_count,
                        'warnings' => $import->warning_count,
                    ]));

                if ($import->status === ImportStatus::Completed) {
                    $notification->success();
                } else {
                    $notification->warning();
                }
            }

            $notification->sendToDatabase($user);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
