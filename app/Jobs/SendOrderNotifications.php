<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Mail\OrderStatusChangedCustomerMail;
use App\Models\Order;
use App\Services\Notifications\JobFanOut;
use App\Services\Notifications\TelegramNotifier;
use App\Settings\NotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fan-out of the notifications of one event (NT-01). It only decides which channels apply (settings toggles)
 * and queues one small job per channel and recipient, so one failing channel neither blocks nor repeats the others.
 */
class SendOrderNotifications implements ShouldQueue
{
    use Queueable;

    public const EVENT_CREATED = 'created';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_EXPIRED = 'expired';

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $orderId,
        public readonly string $event,
        public readonly ?string $toStatus = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(NotificationSettings $settings, TelegramNotifier $telegram): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        $jobs = match ($this->event) {
            self::EVENT_CREATED => $this->created($order, $settings, $telegram),
            self::EVENT_STATUS_CHANGED => $this->statusChanged($order, $settings),
            self::EVENT_EXPIRED => $this->expired($order, $settings, $telegram),
            default => [],
        };

        // All or nothing: a failure rolls the whole group back, so the retry of this job cannot send twice.
        JobFanOut::dispatch($jobs);
    }

    /**
     * @return list<ShouldQueue>
     */
    private function created(Order $order, NotificationSettings $settings, TelegramNotifier $telegram): array
    {
        $jobs = [];

        if ($settings->notify_customer_new && filled($order->customer_email)) {
            $jobs[] = new SendMailNotification($order->id, SendMailNotification::CUSTOMER_PLACED);
        }

        $typeEnabled = match ($order->type) {
            OrderType::Order => $settings->notify_event_order,
            OrderType::Reservation => $settings->notify_event_reservation,
            OrderType::Inquiry => $settings->notify_event_inquiry,
        };

        if (! $typeEnabled) {
            return $jobs;
        }

        if ($settings->notify_shop_email) {
            foreach (array_unique(array_filter($settings->notify_emails)) as $email) {
                $jobs[] = new SendMailNotification($order->id, SendMailNotification::SHOP_NEW, $email);
            }
        }

        if ($settings->telegram_enabled && $settings->notify_shop_telegram && $telegram->isConfigured()) {
            $jobs[] = new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST);
        }

        return $jobs;
    }

    /**
     * @return list<ShouldQueue>
     */
    private function statusChanged(Order $order, NotificationSettings $settings): array
    {
        $status = $this->toStatus !== null ? OrderStatus::tryFrom($this->toStatus) : null;

        if ($status === null || ! OrderStatusChangedCustomerMail::shouldNotify($status)) {
            return [];
        }

        if ($settings->notify_customer_status && filled($order->customer_email)) {
            return [new SendMailNotification($order->id, SendMailNotification::CUSTOMER_STATUS, null, $status->value)];
        }

        return [];
    }

    /**
     * @return list<ShouldQueue>
     */
    private function expired(Order $order, NotificationSettings $settings, TelegramNotifier $telegram): array
    {
        if ($settings->telegram_enabled && $settings->notify_reservation_expired && $telegram->isConfigured()) {
            return [new SendTelegramNotification($order->id, SendTelegramNotification::EXPIRED)];
        }

        return [];
    }
}
