<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrderItemsChanged;
use App\Events\OrderStatusChanged;
use App\Services\Telegram\CustomerTelegramNotifier;
use Throwable;

/**
 * TN-02: the domain events of a request become customer messages in Telegram. The notifier only writes the log row and
 * queues a job; a failure here never reaches the code that created the order or changed its status (TN-04, NT-01).
 *
 * The methods are not called `handle`, so event auto-discovery does not register them a second time.
 */
final class NotifyCustomerInTelegram
{
    public function __construct(private readonly CustomerTelegramNotifier $notifier) {}

    public function onOrderCreated(OrderCreated $event): void
    {
        try {
            $this->notifier->orderCreated($event->order);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function onStatusChanged(OrderStatusChanged $event): void
    {
        // `new` is the state of a created request: the «created» message covers it.
        if ($event->to === OrderStatus::New) {
            return;
        }

        try {
            // «Резерв истёк или снят» is its own event (TN-02); every other transition is a status change.
            if ($event->order->type === OrderType::Reservation && in_array($event->to, [OrderStatus::Expired, OrderStatus::Cancelled], true)) {
                $this->notifier->reservationEnded($event->order, $event->to);
            } else {
                $this->notifier->statusChanged($event->order, $event->to);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function onItemsChanged(OrderItemsChanged $event): void
    {
        // The same «Уведомить покупателя» checkbox that controls the e-mail and the cabinet notice (OE-09).
        if (! $event->notifyCustomer) {
            return;
        }

        try {
            $this->notifier->itemsChanged($event->order, $event->batchUuid, $event->oldTotal, $event->newTotal);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
