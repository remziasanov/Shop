<?php

declare(strict_types=1);

namespace App\Enums;

use App\Settings\SalesSettings;

/**
 * Kind of customer request (TZ 6.9): an order from the cart, a one-product reservation or an inquiry.
 */
enum OrderType: string
{
    case Order = 'order';
    case Reservation = 'reservation';
    case Inquiry = 'inquiry';

    public function label(): string
    {
        return __('sales.types.'.$this->value);
    }

    public function pluralLabel(): string
    {
        return __('sales.types_plural.'.$this->value);
    }

    /**
     * Number prefix from the "Sales" settings (D-045): Z / R / Q by default.
     */
    public function prefix(?SalesSettings $settings = null): string
    {
        $settings ??= app(SalesSettings::class);

        return match ($this) {
            self::Order => $settings->order_prefix,
            self::Reservation => $settings->reservation_prefix,
            self::Inquiry => $settings->inquiry_prefix,
        };
    }

    /**
     * Statuses a request of this type can have, in lifecycle order (Appendix B).
     *
     * @return list<OrderStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Order => [OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Ready, OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Cancelled],
            self::Reservation => [OrderStatus::New, OrderStatus::Reserved, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Expired],
            self::Inquiry => [OrderStatus::New, OrderStatus::InProgress, OrderStatus::Closed],
        };
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
