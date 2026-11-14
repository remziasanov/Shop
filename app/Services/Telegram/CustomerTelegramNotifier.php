<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\CustomerTelegramEvent;
use App\Enums\OrderStatus;
use App\Enums\TelegramMessageStatus;
use App\Jobs\SendCustomerTelegramMessage;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Export;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use App\Models\WholesaleApplication;
use App\Services\Customers\CustomerNotificationService;
use App\Settings\TelegramSettings;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Customer notifications in Telegram (TN-01…TN-07). Each event method decides whether a message goes out, writes the
 * `telegram_messages` row (unique `dedupe_key`, TN-04) and queues the delivery job; nothing is sent from here (NF-60).
 *
 * What happens when a condition fails:
 *  - the feature or the event is off in the shop, the bot is not connected, there is no recipient, or a message with
 *    this key exists already: nothing is written (NF-63: the shop behaves as before);
 *  - the recipient exists but cannot be written to (chat not started, bot blocked, `/stop`, the event switched off in
 *    the cabinet): a `skipped` row records why, so the order card explains the silence (TN-07).
 */
class CustomerTelegramNotifier
{
    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramBotSetup $setup,
        private readonly CustomerTelegramMessageBuilder $builder,
    ) {}

    /**
     * One message per request (TN-02): a mixed cart (MR-11) creates an order and a reservation, each announced in its
     * own message (D-151: simpler and every message keeps its own «Открыть заказ» button).
     */
    public function orderCreated(Order $order): ?TelegramMessage
    {
        return $this->send(
            CustomerTelegramEvent::OrderCreated,
            $order,
            null,
            'created:'.$order->id,
            fn (): array => [$this->builder->created($order), $this->builder->markup($order)],
        );
    }

    public function statusChanged(Order $order, OrderStatus $to): ?TelegramMessage
    {
        // `version` moves with every status change: a repeated «reserved» (new term) is a new message, a repeated event is not.
        return $this->send(
            CustomerTelegramEvent::StatusChanged,
            $order,
            null,
            'status:'.$order->id.':'.$to->value.':'.$order->version,
            fn (): array => [$this->builder->status($order, $to), $this->builder->markup($order)],
        );
    }

    /** The reservation expired (`expired`) or was cancelled (`cancelled`): «резерв истёк или снят». */
    public function reservationEnded(Order $order, OrderStatus $to): ?TelegramMessage
    {
        return $this->send(
            CustomerTelegramEvent::ReservationExpired,
            $order,
            null,
            'reservation-ended:'.$order->id.':'.$to->value,
            fn (): array => [$this->builder->reservationEnded($order, $to), $this->builder->markup($order)],
        );
    }

    public function reservationExpiring(Order $order): ?TelegramMessage
    {
        if ($order->reserved_until === null) {
            return null;
        }

        return $this->send(
            CustomerTelegramEvent::ReservationExpiring,
            $order,
            null,
            'expiring:'.$order->id.':'.$order->reserved_until->getTimestamp(),
            fn (): array => [$this->builder->expiring($order), $this->builder->markup($order, withCall: true)],
        );
    }

    public function itemsChanged(Order $order, string $batchUuid, int $oldTotal, int $newTotal): ?TelegramMessage
    {
        return $this->send(
            CustomerTelegramEvent::ItemsChanged,
            $order,
            null,
            'items:'.$order->id.':'.$batchUuid,
            fn (): array => [$this->builder->itemsChanged($order, $oldTotal, $newTotal), $this->builder->markup($order)],
        );
    }

    public function wholesaleDecision(WholesaleApplication $application, ?CustomerGroup $group): ?TelegramMessage
    {
        $customer = Customer::query()->find($application->customer_id);

        return $this->send(
            CustomerTelegramEvent::WholesaleDecision,
            null,
            $customer,
            'wholesale:'.$application->id.':'.$application->status->value.':'.($application->decided_at?->getTimestamp() ?? 0),
            fn (): array => [$this->builder->wholesale($application, $group?->name), $this->builder->markup(null)],
        );
    }

    public function exportReady(Export $export, Customer $customer, string $format): ?TelegramMessage
    {
        return $this->send(
            CustomerTelegramEvent::ExportReady,
            null,
            $customer,
            'export:'.$export->id,
            fn (): array => [$this->builder->exportReady($export, $format), $this->builder->markup(null)],
        );
    }

    /**
     * «Резерв истекает через 6 часов» for the customer, run by the same scheduled task as the shop warning. Safe to
     * repeat (the dedupe key holds the reservation term). Stops at `$deadline` (`microtime(true)`).
     *
     * @return int messages queued
     */
    public function notifyExpiringReservations(?float $deadline = null): int
    {
        if (! $this->isActive(CustomerTelegramEvent::ReservationExpiring)) {
            return 0;
        }

        $queued = 0;
        $lastId = 0;

        do {
            $orders = Order::query()
                ->expiringWithin(CustomerNotificationService::EXPIRING_WINDOW_HOURS)
                ->where(fn ($query) => $query->whereNotNull('orders.customer_id')->orWhereNotNull('orders.telegram_account_id'))
                ->where('orders.id', '>', $lastId)
                ->orderBy('orders.id')
                ->limit(100)
                ->get();

            foreach ($orders as $order) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return $queued;
                }

                $lastId = $order->id;

                try {
                    if ($this->reservationExpiring($order)?->status === TelegramMessageStatus::Queued) {
                        $queued++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } while ($orders->count() === 100);

        return $queued;
    }

    /** The feature, the bot and the shop-side switch of the event allow messages (NF-63). */
    public function isActive(CustomerTelegramEvent $event): bool
    {
        return $this->settings->customer_notifications_enabled
            && $this->setup->isConnected()
            && (bool) $this->settings->{$event->settingName()};
    }

    /**
     * TN-01: the recipient of a request, `orders.telegram_account_id` first, else the Telegram of the order's customer.
     * Never an account of another customer (NF-62).
     */
    public function recipient(?Order $order, ?Customer $customer = null): ?TelegramAccount
    {
        return $this->resolve($order, $customer)[0] ?? null;
    }

    /**
     * The send-time check of the delivery job (TN-01, TN-05, NF-63): the recipient is resolved again, because the shop
     * switch, the customer's own switch or the link of the account may have changed during the queue delay.
     *
     * @return string|null the reason to skip the queued row, null when it may go out
     */
    public function deliveryBlock(TelegramMessage $row): ?string
    {
        $event = CustomerTelegramEvent::tryFrom($row->kind->value);
        $account = $row->account;

        if ($event === null || $account === null) {
            return 'recipient_unavailable';
        }

        if (! $this->isActive($event)) {
            return 'feature_disabled';
        }

        $order = $row->order_id !== null ? Order::query()->find($row->order_id) : null;

        if ($order !== null) {
            $resolved = $this->resolve($order, null);

            // The account must still be the recipient of this request (TN-01): not unlinked, not relinked to another customer.
            if ($resolved === null || $resolved[0]->id !== $account->id) {
                return 'recipient_changed';
            }

            $customer = $resolved[1];
        } else {
            $customer = $account->customer;

            // Wholesale and export messages go to the linked Telegram of the customer only.
            if ($customer === null || $customer->anonymized_at !== null) {
                return 'recipient_changed';
            }
        }

        return $this->skipReason($event, $account, $customer);
    }

    /**
     * @return array{0: TelegramAccount, 1: Customer|null}|null the account and the customer whose cabinet settings apply
     */
    private function resolve(?Order $order, ?Customer $customer): ?array
    {
        if ($order !== null && $order->customer_id !== null) {
            $customer = Customer::query()->find($order->customer_id);
        }

        if ($customer !== null && $customer->anonymized_at !== null) {
            return null;
        }

        $account = null;

        if ($order?->telegram_account_id !== null) {
            $account = TelegramAccount::query()->find($order->telegram_account_id);
        }

        $account ??= $customer?->telegramAccount;

        if ($account === null) {
            return null;
        }

        // The order belongs to one customer, the Telegram to another: the data is not theirs to read.
        if ($customer !== null && $account->customer_id !== null && $account->customer_id !== $customer->id) {
            return null;
        }

        return [$account, $customer ?? $account->customer];
    }

    /**
     * @param  Closure(): array{0: string, 1: array<string, mixed>}  $render  text and reply markup, built only when a message goes out
     */
    private function send(CustomerTelegramEvent $event, ?Order $order, ?Customer $customer, string $dedupeKey, Closure $render): ?TelegramMessage
    {
        if (! $this->isActive($event)) {
            return null;
        }

        $resolved = $this->resolve($order, $customer);

        if ($resolved === null || TelegramMessage::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        [$account, $owner] = $resolved;
        $skip = $this->skipReason($event, $account, $owner);

        $row = $this->record($event, $account, $order, $dedupeKey, $skip === null ? TelegramMessageStatus::Queued : TelegramMessageStatus::Skipped, $skip);

        if ($row === null || $skip !== null) {
            return $row;
        }

        [$text, $markup] = $render();

        try {
            SendCustomerTelegramMessage::dispatch($row->id, $text, ['reply_markup' => $markup]);
        } catch (Throwable $e) {
            report($e);
            $row->forceFill(['status' => TelegramMessageStatus::Failed, 'error' => 'queue_error'])->save();
        }

        return $row;
    }

    /**
     * Why this recipient gets nothing, or null (TN-01).
     */
    private function skipReason(CustomerTelegramEvent $event, TelegramAccount $account, ?Customer $customer): ?string
    {
        if ($account->chat_started_at === null) {
            return 'chat_not_started';
        }

        if ($account->bot_blocked_at !== null) {
            return 'bot_blocked';
        }

        if (! $account->notify_enabled) {
            return 'notifications_stopped';
        }

        if ($customer !== null && in_array($event->value, $customer->telegram_notify_events ?? [], true)) {
            return 'disabled_by_customer';
        }

        return null;
    }

    /**
     * Writes the row before anything is sent (TN-04); null when a parallel request wrote the same key first.
     */
    private function record(CustomerTelegramEvent $event, TelegramAccount $account, ?Order $order, string $dedupeKey, TelegramMessageStatus $status, ?string $reason): ?TelegramMessage
    {
        try {
            return TelegramMessage::query()->create([
                'telegram_account_id' => $account->id,
                'kind' => $event->kind(),
                'order_id' => $order?->id,
                'dedupe_key' => $dedupeKey,
                'status' => $status,
                'error' => $reason,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
