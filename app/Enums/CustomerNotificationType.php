<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kind of a cabinet notification (CA-18). Stored as a string in `customer_notifications.type`. Wholesale decisions
 * (CG-22) and finished exports (section 18) are written by the features that own them.
 */
enum CustomerNotificationType: string
{
    case OrderStatus = 'order_status';
    case ReservationExpiring = 'reservation_expiring';
    case ReservationExpired = 'reservation_expired';
    case WholesaleDecision = 'wholesale_decision';
    case ExportReady = 'export_ready';
    case OrderChanged = 'order_changed';
    case TelegramLinked = 'telegram_linked';
    /** TM-04: the Telegram of the customer was linked to another account or disconnected. */
    case TelegramUnlinked = 'telegram_unlinked';

    public function label(): string
    {
        return __('account.notifications.types.'.$this->value);
    }
}
