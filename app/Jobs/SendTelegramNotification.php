<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\Notifications\Messages\NewRequestMessage;
use App\Services\Notifications\Messages\OrderMessage;
use App\Services\Notifications\Messages\ReservationExpiredMessage;
use App\Services\Notifications\Messages\ReservationExpiringMessage;
use App\Services\Notifications\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One Telegram message about a request (NT-01, NT-02). The API error is thrown, so the queue retries it.
 */
class SendTelegramNotification implements ShouldQueue
{
    use Queueable;

    public const NEW_REQUEST = 'new';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderId, public readonly string $kind) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TelegramNotifier $telegram): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        $message = match ($this->kind) {
            self::NEW_REQUEST => new NewRequestMessage,
            self::EXPIRING => new ReservationExpiringMessage,
            self::EXPIRED => new ReservationExpiredMessage,
            default => null,
        };

        if ($message instanceof OrderMessage) {
            $telegram->send($message->render($order));
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
