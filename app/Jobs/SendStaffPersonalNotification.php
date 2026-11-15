<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\DeliversTelegramMessages;
use App\Models\Order;
use App\Models\User;
use App\Services\Telegram\Staff\StaffOrderNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TS-08: the personal message of one staff member about a new request. The right to see requests is checked by the
 * notifier right before sending, not when the job was queued. Carries two ids, never the token (NF-61).
 */
class SendStaffPersonalNotification implements ShouldBeUnique, ShouldQueue
{
    use DeliversTelegramMessages;
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $orderId, public readonly int $userId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return 'staff-personal:'.$this->orderId.':'.$this->userId;
    }

    public function handle(StaffOrderNotifier $staff): void
    {
        $order = Order::query()->find($this->orderId);
        $user = User::query()->find($this->userId);

        if ($order === null || $user === null) {
            return;
        }

        $this->deliverTelegram(function () use ($staff, $order, $user): void {
            $staff->sendPersonal($order, $user);
        });
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Staff personal Telegram notification failed', [
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
