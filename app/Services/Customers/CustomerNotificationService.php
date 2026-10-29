<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\CustomerNotificationType;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Order;

/**
 * Notifications of the customer cabinet (CA-18): created next to the e-mails, shown in the cabinet with an unread
 * counter in the header, kept 90 days. Every read and write is scoped by the customer id (CA-28).
 */
final class CustomerNotificationService
{
    /** Hours before the end of a reservation term when the customer is warned. */
    public const EXPIRING_WINDOW_HOURS = 6;

    /**
     * Creates a notification. `$dedupeKey` makes the call idempotent: a second call with the same key for the same
     * customer creates nothing and returns null. An anonymized customer gets nothing.
     */
    public function notify(
        Customer $customer,
        CustomerNotificationType $type,
        string $title,
        string $body,
        ?string $url = null,
        ?string $dedupeKey = null,
    ): ?CustomerNotification {
        if ($customer->anonymized_at !== null) {
            return null;
        }

        if ($dedupeKey !== null && CustomerNotification::query()->where('customer_id', $customer->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $notification = new CustomerNotification([
            'customer_id' => $customer->id,
            'type' => $type->value,
            'title' => mb_substr($title, 0, 250),
            'body' => $body,
            'url' => $url,
            'dedupe_key' => $dedupeKey,
        ]);
        $notification->save();

        return $notification;
    }

    /**
     * A status of a request of the customer changed (the same events that produce a status e-mail, plus the
     * expiry of a reservation by the system).
     */
    public function notifyStatusChanged(Order $order, OrderStatus $to): ?CustomerNotification
    {
        $customer = $this->ownerOf($order);

        if ($customer === null) {
            return null;
        }

        if ($to === OrderStatus::Expired) {
            return $this->notify(
                $customer,
                CustomerNotificationType::ReservationExpired,
                __('account.notifications.expired_title', ['number' => $order->number]),
                __('account.notifications.expired_body', ['number' => $order->number]),
                route('customer.orders.show', $order->uuid, false),
                'expired:'.$order->id,
            );
        }

        return $this->notify(
            $customer,
            CustomerNotificationType::OrderStatus,
            __('account.notifications.status_title', ['type' => $order->type->label(), 'number' => $order->number]),
            __('account.notifications.status_body', ['status' => $to->label($order->type)]),
            route('customer.orders.show', $order->uuid, false),
        );
    }

    /**
     * «Резерв истекает»: one notification per reservation and term (a prolonged term warns again).
     */
    public function notifyReservationExpiring(Order $order): ?CustomerNotification
    {
        $customer = $this->ownerOf($order);

        if ($customer === null || $order->reserved_until === null) {
            return null;
        }

        return $this->notify(
            $customer,
            CustomerNotificationType::ReservationExpiring,
            __('account.notifications.expiring_title', ['number' => $order->number]),
            __('account.notifications.expiring_body', ['number' => $order->number, 'date' => $order->reserved_until->format('d.m.Y H:i')]),
            route('customer.orders.show', $order->uuid, false),
            'expiring:'.$order->id.':'.$order->reserved_until->getTimestamp(),
        );
    }

    /**
     * Warns the owners of active reservations that expire within the window. Safe to repeat. Stops at `$deadline`
     * (`microtime(true)`); the next run continues.
     */
    public function notifyExpiringReservations(?float $deadline = null): int
    {
        $created = 0;
        $lastId = 0;

        do {
            $orders = Order::query()
                ->expiringWithin(self::EXPIRING_WINDOW_HOURS)
                ->whereNotNull('orders.customer_id')
                ->where('orders.id', '>', $lastId)
                ->orderBy('orders.id')
                ->limit(100)
                ->get();

            foreach ($orders as $order) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return $created;
                }

                $lastId = $order->id;

                if ($this->notifyReservationExpiring($order) !== null) {
                    $created++;
                }
            }
        } while ($orders->count() === 100);

        return $created;
    }

    public function unreadCount(Customer $customer): int
    {
        return CustomerNotification::query()->where('customer_id', $customer->id)->unread()->count();
    }

    /**
     * Marks one notification of the customer as read; false when it is not theirs or does not exist.
     */
    public function markRead(Customer $customer, int $notificationId): bool
    {
        $notification = CustomerNotification::query()
            ->where('customer_id', $customer->id)
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            return false;
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return true;
    }

    public function markAllRead(Customer $customer): int
    {
        return CustomerNotification::query()->where('customer_id', $customer->id)->unread()->update(['read_at' => now()]);
    }

    /**
     * Deletes notifications older than the retention period (CA-18, called by `shop:cleanup`); in chunks.
     */
    public function purgeOlderThan(int $days = CustomerNotification::RETENTION_DAYS, ?float $deadline = null): int
    {
        $threshold = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = CustomerNotification::query()->where('created_at', '<', $threshold)->orderBy('id')->limit(1000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += CustomerNotification::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === 1000 && ($deadline === null || microtime(true) < $deadline));

        return $deleted;
    }

    private function ownerOf(Order $order): ?Customer
    {
        if ($order->customer_id === null) {
            return null;
        }

        $customer = Customer::query()->find($order->customer_id);

        return $customer !== null && $customer->anonymized_at === null ? $customer : null;
    }
}
