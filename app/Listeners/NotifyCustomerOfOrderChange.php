<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderItemsChanged;
use App\Jobs\SendOrderChangedMail;
use App\Services\Customers\CustomerNotificationService;
use Throwable;

/**
 * OE-09: after an order was edited in the admin, the customer gets the «Состав заказа изменён» e-mail (when the order
 * has an address) and a cabinet notice (CA-18, when it belongs to an account) - both only when the manager left the
 * «Уведомить покупателя» checkbox on. A failure never reaches the code that saved the edit.
 */
final class NotifyCustomerOfOrderChange
{
    public function __construct(private readonly CustomerNotificationService $notifications) {}

    public function onItemsChanged(OrderItemsChanged $event): void
    {
        if (! $event->notifyCustomer) {
            return;
        }

        if (filled($event->order->customer_email)) {
            try {
                SendOrderChangedMail::dispatch($event->order->id, $event->oldTotal, $event->newTotal);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($event->order->customer_id !== null) {
            try {
                $this->notifications->notifyOrderChanged($event->order, $event->batchUuid);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
