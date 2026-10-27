<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Pricing\BulkPriceChanger;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a bulk base price change (AD-42) to up to {@see BulkPriceChanger::JOB_SIZE} products. The job carries
 * the exact expected old and the new price of every product (computed when the action was submitted) and only
 * touches products whose base price still equals the old one, so a retry or a duplicate run changes nothing
 * twice (D-032). The acting user is passed explicitly: queue workers have no authenticated user.
 */
class ApplyBulkPriceChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 40;

    /**
     * @param  array<int, array{old: int, new: int}>  $targets  product id => base price in kopecks before / after
     */
    public function __construct(public array $targets, public ?int $userId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(BulkPriceChanger $changer): void
    {
        $result = $changer->applyTargets($this->targets, $this->userId);

        if ($result['skipped'] > 0) {
            Log::info('Bulk price change skipped products whose price changed meanwhile.', $result);

            $this->notifySkipped($result['skipped']);
        }
    }

    /**
     * Tell the acting user that some products kept their price because it changed after the preview
     * (for example a second bulk change that ran first). Never fails the job.
     */
    private function notifySkipped(int $skipped): void
    {
        $user = $this->userId !== null ? User::query()->find($this->userId) : null;

        if ($user === null) {
            return;
        }

        try {
            Notification::make()
                ->title(__('admin_pricing.bulk.skipped', ['count' => $skipped]))
                ->warning()
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
