<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\DeliversTelegramMessages;
use App\Models\Order;
use App\Services\Notifications\Messages\OrderMessage;
use App\Services\Notifications\Messages\ReservationExpiredMessage;
use App\Services\Notifications\Messages\ReservationExpiringMessage;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Telegram\Staff\StaffOrderNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Telegram message about a request (NT-01, NT-02). The API error is thrown, so the queue retries it.
 */
class SendTelegramNotification implements ShouldBeUnique, ShouldQueue
{
    use DeliversTelegramMessages;
    use Queueable;

    public const NEW_REQUEST = 'new';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    /** Three failed attempts end the job; releases for rate limits do not count (see the trait). */
    public int $maxExceptions = 3;

    public int $timeout = 30;

    /** Seconds a queued or running twin of this job blocks a duplicate (a parent retry must not resend). */
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $orderId, public readonly string $kind) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return implode(':', ['telegram', $this->orderId, $this->kind]);
    }

    public function handle(TelegramNotifier $telegram, ?StaffOrderNotifier $staff = null): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        // TS-01: the new request goes with its action buttons and is logged for the redraw after a tap.
        if ($this->kind === self::NEW_REQUEST) {
            $staff ??= app(StaffOrderNotifier::class);
            $this->deliverTelegram(function () use ($staff, $order): void {
                $staff->sendToWorkChat($order);
            });

            return;
        }

        $message = match ($this->kind) {
            self::EXPIRING => new ReservationExpiringMessage,
            self::EXPIRED => new ReservationExpiredMessage,
            default => null,
        };

        if ($message instanceof OrderMessage) {
            $this->deliverTelegram(fn () => $telegram->send($message->render($order)));
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Telegram notification failed', [
            'order_id' => $this->orderId,
            'kind' => $this->kind,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
