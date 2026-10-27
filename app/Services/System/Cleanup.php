<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Services\Cart\CartService;
use App\Services\Export\ExportManager;
use App\Services\Import\ImportWizard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly housekeeping (`shop:cleanup`, TZ section 8): old carts, import and export files, abandoned uploads.
 * Idempotent; every step checks the time budget so a run stays well under a minute on shared hosting.
 */
final class Cleanup
{
    /** Age in hours after which temporary uploads are considered abandoned. */
    public const TEMP_HOURS = 24;

    public const CART_DAYS = 30;

    /** @var list<string> */
    public const TEMP_DIRECTORIES = ['tmp', ImportWizard::UPLOAD_DIRECTORY];

    public function __construct(
        private readonly CartService $carts,
        private readonly ExportManager $exports,
    ) {}

    /**
     * @return array{carts: int, imports: int, temp_files: int, exports: int, livewire: int, skipped: bool}
     */
    public function run(float $deadline): array
    {
        $summary = ['carts' => 0, 'imports' => 0, 'temp_files' => 0, 'exports' => 0, 'livewire' => 0, 'skipped' => false];

        $steps = [
            'carts' => fn (): int => $this->carts->clearOld(self::CART_DAYS),
            'imports' => fn (): int => $this->purgeImports($deadline),
            'temp_files' => fn (): int => $this->purgeTemporary($deadline),
            'exports' => fn (): int => $this->exports->purgeOlderThan(),
            'livewire' => fn (): int => $this->purgeLivewire($deadline),
        ];

        foreach ($steps as $key => $step) {
            if (microtime(true) >= $deadline) {
                $summary['skipped'] = true;

                break;
            }

            $summary[$key] = $step();
        }

        return $summary;
    }

    /**
     * Deletes files of imports older than the retention period; the journal rows stay (IM-13).
     */
    private function purgeImports(float $deadline): int
    {
        $days = (int) config('shop.import_retention_days', 30);
        $disk = Storage::disk('local');
        $purged = 0;

        $running = array_map(fn (ImportStatus $s): string => $s->value, array_filter(ImportStatus::cases(), fn (ImportStatus $s): bool => $s->isRunning()));

        Import::query()
            ->whereNull('files_purged_at')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('status', $running)
            ->eachById(function (Import $import) use ($disk, &$purged, $deadline): bool {
                if (microtime(true) >= $deadline) {
                    return false;
                }

                $disk->deleteDirectory('imports/'.$import->id);
                $import->update(['files_purged_at' => now()]);
                $purged++;

                return true;
            });

        return $purged;
    }

    /**
     * Abandoned uploads: `tmp/*` (product, category and home images) and `imports/uploads/` on the private disk.
     */
    private function purgeTemporary(float $deadline): int
    {
        $count = 0;

        foreach (self::TEMP_DIRECTORIES as $directory) {
            $count += $this->deleteOldFiles(Storage::disk('local'), $directory, $deadline);
        }

        return $count;
    }

    /**
     * Livewire temporary uploads (`livewire-tmp`) older than a day.
     */
    private function purgeLivewire(float $deadline): int
    {
        $diskName = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');
        $directory = trim((string) (config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp'), '/');

        return $this->deleteOldFiles(Storage::disk((string) $diskName), $directory, $deadline);
    }

    private function deleteOldFiles(Filesystem $disk, string $directory, float $deadline): int
    {
        if (! $disk->exists($directory)) {
            return 0;
        }

        $threshold = now()->subHours(self::TEMP_HOURS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles($directory) as $path) {
            if (microtime(true) >= $deadline) {
                break;
            }

            if ($disk->lastModified($path) < $threshold && $disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
