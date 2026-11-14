<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A button action of the staff in Telegram (TS-01, TS-03): the short code travels in `callback_data`
 * (`o:{code}:{order id}:{version}`, at most 64 bytes). The chat buttons are the six actions TS-01 lists; the «Рабочее место»
 * (TS-06) offers in addition the workplace-only `Ship` and `Complete` (Appendix B: «Отправлен», «Выполнен» / «Выкуплен»).
 * The case order is the order of the buttons.
 */
enum StaffOrderAction: string
{
    case Confirm = 'cf';
    case Ready = 'rd';
    case InProgress = 'ip';
    case Extend = 'ex';
    case Close = 'cl';
    case Ship = 'sh';
    case Complete = 'co';
    case Cancel = 'cx';

    /** Hours added by the «Продлить на 24 ч» button. */
    public const EXTEND_HOURS = 24;

    /**
     * The status the action leads to for a request of this type; null for the extension (no status change) and
     * for an action that does not exist for the type.
     */
    public function targetStatus(OrderType $type): ?OrderStatus
    {
        return match ($this) {
            self::Confirm => match ($type) {
                OrderType::Order => OrderStatus::Confirmed,
                OrderType::Reservation => OrderStatus::Reserved,
                OrderType::Inquiry => null,
            },
            self::Ready => $type === OrderType::Order ? OrderStatus::Ready : null,
            self::InProgress => $type === OrderType::Inquiry ? OrderStatus::InProgress : null,
            self::Close => $type === OrderType::Inquiry ? OrderStatus::Closed : null,
            self::Ship => $type === OrderType::Order ? OrderStatus::Shipped : null,
            self::Complete => $type === OrderType::Inquiry ? null : OrderStatus::Completed,
            self::Cancel => $type === OrderType::Inquiry ? null : OrderStatus::Cancelled,
            self::Extend => null,
        };
    }

    /** Only the «Рабочее место» offers it; a chat button with this code is refused (TS-01 lists no such button). */
    public function isWorkplaceOnly(): bool
    {
        return $this === self::Ship || $this === self::Complete;
    }

    /** Needs two taps (TS-04). */
    public function isDangerous(): bool
    {
        return $this === self::Cancel;
    }

    public function label(?OrderType $type = null): string
    {
        $key = match (true) {
            $this === self::Confirm && $type === OrderType::Reservation => 'confirm_reservation',
            $this === self::Complete && $type === OrderType::Reservation => 'complete_reservation',
            default => $this->name,
        };

        return __('telegram_staff.action.'.strtolower($key), ['hours' => self::EXTEND_HOURS]);
    }
}
