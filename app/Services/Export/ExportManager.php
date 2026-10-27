<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Jobs\ExportCatalog;
use App\Jobs\ExportOrders;
use App\Models\Export;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Starts exports and keeps the journal (EX-03): small exports (up to `shop.export_sync_limit` rows) are built
 * at once, larger ones in the queue. Files live on the private disk in `exports/{id}/` for 7 days.
 *
 * @phpstan-import-type Filters from CatalogExporter
 * @phpstan-import-type Params from \App\Services\Orders\OrderQuery
 */
final class ExportManager
{
    public const DISK = 'local';

    public function __construct(
        private readonly CatalogExporter $catalog,
        private readonly OrdersExporter $orders,
    ) {}

    /**
     * Rows an export with these parameters would have.
     *
     * @param  array<string, mixed>  $filters  catalog {@see Filters} or request {@see Params}
     */
    public function count(ExportType $type, array $filters): int
    {
        /** @var Filters&Params $filters */
        return match ($type) {
            ExportType::Catalog => $this->catalog->count($filters),
            ExportType::Orders => $this->orders->count($filters),
        };
    }

    /**
     * @param  array<string, mixed>  $filters  catalog {@see Filters} or request {@see Params}
     */
    public function request(ExportType $type, array $filters, ?User $user): Export
    {
        $rows = $this->count($type, $filters);

        $export = Export::query()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'status' => ExportStatus::Queued,
            'params' => $filters,
            'rows' => 0,
        ]);

        if ($rows <= (int) config('shop.export_sync_limit')) {
            $this->generate($export, notify: false);

            return $export->refresh();
        }

        match ($type) {
            ExportType::Catalog => ExportCatalog::dispatch($export->id),
            ExportType::Orders => ExportOrders::dispatch($export->id),
        };

        return $export;
    }

    /**
     * Build the file of a queued export. Used by the job and for small synchronous exports.
     */
    public function generate(Export $export, bool $notify = true): Export
    {
        $export->update(['status' => ExportStatus::Processing, 'failure_message' => null]);

        $relative = 'exports/'.$export->id.'/'.$export->type->value.'-'.now()->format('Ymd-His').'.xlsx';
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($relative));

        try {
            $params = $export->params ?? [];
            $rows = match ($export->type) {
                ExportType::Catalog => $this->catalog->export($params, $disk->path($relative)),
                ExportType::Orders => $this->orders->export($params, $disk->path($relative)),
            };
        } catch (Throwable $e) {
            $disk->delete($relative);
            $export->update(['status' => ExportStatus::Failed, 'failure_message' => mb_substr($e->getMessage(), 0, 1000)]);

            if ($notify) {
                $this->notify($export, failed: true);
            }

            throw $e;
        }

        $export->update(['status' => ExportStatus::Completed, 'file_path' => $relative, 'rows' => $rows]);

        if ($notify) {
            $this->notify($export);
        }

        return $export;
    }

    public function markFailed(Export $export, string $message): void
    {
        $export->update(['status' => ExportStatus::Failed, 'failure_message' => mb_substr($message, 0, 1000)]);
        $this->notify($export, failed: true);
    }

    public function fileExists(Export $export): bool
    {
        return $export->status === ExportStatus::Completed
            && $export->file_path !== null
            && Storage::disk(self::DISK)->exists($export->file_path);
    }

    /**
     * Delete files of exports older than the given number of days (default `shop.export_retention_days`);
     * the journal rows stay. Returns the number of purged files.
     */
    public function purgeOlderThan(?int $days = null): int
    {
        $days ??= (int) config('shop.export_retention_days');
        $disk = Storage::disk(self::DISK);
        $purged = 0;

        Export::query()
            ->whereNotNull('file_path')
            ->where('created_at', '<', now()->subDays($days))
            // lazyById, not each(): offset paging would skip rows because the loop clears file_path.
            ->lazyById(500)
            ->each(function (Export $export) use ($disk, &$purged): void {
                $disk->deleteDirectory('exports/'.$export->id);
                $export->update(['file_path' => null]);
                $purged++;
            });

        return $purged;
    }

    private function notify(Export $export, bool $failed = false): void
    {
        $user = $export->user;

        if ($user === null) {
            return;
        }

        $notification = Notification::make();

        if ($failed) {
            $notification->danger()->title(__('export.notifications.failed'));
        } else {
            $notification->success()
                ->title(__('export.notifications.ready', ['rows' => $export->rows]))
                ->body(__('export.notifications.ready_body', ['days' => config('shop.export_retention_days')]))
                ->actions([
                    Action::make('download')
                        ->label(__('export.notifications.download'))
                        ->button()
                        ->url(route('admin.exports.download', $export), shouldOpenInNewTab: true),
                ]);
        }

        $notification->sendToDatabase($user);
    }
}
