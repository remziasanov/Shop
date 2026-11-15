<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The events the shop reports to a customer in Telegram (TN-02). The values equal the customer kinds of
 * {@see TelegramMessageKind}; they are also the keys of `customers.telegram_notify_events` (the switched-off list, TN-05).
 */
enum CustomerTelegramEvent: string
{
    case OrderCreated = 'order_created';
    case StatusChanged = 'status_changed';
    case ReservationExpiring = 'reservation_expiring';
    case ReservationExpired = 'reservation_expired';
    case ItemsChanged = 'items_changed';
    case WholesaleDecision = 'wholesale_decision';
    case ExportReady = 'export_ready';

    /** Name of the boolean property of `TelegramSettings` that switches the event on in the shop. */
    public function settingName(): string
    {
        return 'notify_'.$this->value;
    }

    public function kind(): TelegramMessageKind
    {
        return TelegramMessageKind::from($this->value);
    }

    /** Shop-side label (settings page). */
    public function label(): string
    {
        return __('telegram_notify.events.'.$this->value);
    }

    /** Label for the customer (cabinet toggles). */
    public function customerLabel(): string
    {
        return __('telegram_notify.cabinet.events.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Message kinds written to the customer (TN-07 block of the order card).
     *
     * @return list<TelegramMessageKind>
     */
    public static function kinds(): array
    {
        return array_map(fn (self $case): TelegramMessageKind => $case->kind(), self::cases());
    }
}
