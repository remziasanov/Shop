<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of an outgoing Telegram message (TN-07, TS-03): what event it reports.
 */
enum TelegramMessageKind: string
{
    case OrderCreated = 'order_created';
    case StatusChanged = 'status_changed';
    case ReservationExpiring = 'reservation_expiring';
    case ReservationExpired = 'reservation_expired';
    case ItemsChanged = 'items_changed';
    case WholesaleDecision = 'wholesale_decision';
    case ExportReady = 'export_ready';
    case StaffNewOrder = 'staff_new_order';
    case StaffOrderAction = 'staff_order_action';

    public function label(): string
    {
        return __('telegram.message_kind.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
