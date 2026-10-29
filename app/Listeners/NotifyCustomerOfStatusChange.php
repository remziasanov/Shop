<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Mail\OrderStatusChangedCustomerMail;
use App\Services\Customers\CustomerNotificationService;
use Throwable;

/**
 * CA-18: every status change that is mailed to the customer (and the system expiry of a reservation) also appears in
 * the cabinet, for requests placed under an account. A failure never reaches the code that changed the status.
 */
final class NotifyCustomerOfStatusChange
{
    public function __construct(private readonly CustomerNotificationService $notifications) {}

    public function onStatusChanged(OrderStatusChanged $event): void
    {
        if ($event->order->customer_id === null) {
            return;
        }

        $expiredBySystem = $event->to === OrderStatus::Expired && $event->bySystem;

        if (! $expiredBySystem && ! OrderStatusChangedCustomerMail::shouldNotify($event->to)) {
            return;
        }

        try {
            $this->notifications->notifyStatusChanged($event->order, $event->to);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
