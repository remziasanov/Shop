<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class NotificationSettings extends Settings
{
    /** @var array<int, string> */
    public array $notify_emails;

    public bool $telegram_enabled;

    /** Secret, stored encrypted. Never seeded. */
    public ?string $telegram_bot_token;

    public ?string $telegram_chat_id;

    /** New order / reservation / inquiry: email to the shop. */
    public bool $notify_shop_email;

    /** New order / reservation / inquiry: Telegram to the shop. */
    public bool $notify_shop_telegram;

    /** New order / reservation / inquiry: email to the customer. */
    public bool $notify_customer_new;

    /** Status changes (confirmed, ready, cancelled, reservation confirmed): email to the customer. */
    public bool $notify_customer_status;

    /** Reservation expires within 6 hours: Telegram to the shop. */
    public bool $notify_reservation_expiring;

    /** Reservation expired automatically: Telegram to the shop. */
    public bool $notify_reservation_expired;

    /**
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return ['telegram_bot_token'];
    }

    public static function group(): string
    {
        return 'notifications';
    }
}
