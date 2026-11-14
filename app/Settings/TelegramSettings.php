<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Everything about the shop's single Telegram bot (TG-01, D-100): token, work chat, bot identity, texts and feature
 * switches. The shop-side notification switches stay in NotificationSettings. Secrets are stored encrypted and are
 * never shown in forms, logs or queued jobs (NF-61).
 */
class TelegramSettings extends Settings
{
    /** Notifications to the shop's work chat are on (was `notifications.telegram_enabled`). */
    public bool $enabled;

    /** Secret, encrypted (was `notifications.telegram_bot_token`). Never seeded. */
    public ?string $bot_token;

    /** Work chat of the shop, kept as a string: may be negative or `@channel` (was `notifications.telegram_chat_id`). */
    public ?string $work_chat_id;

    /** Secret, encrypted: `X-Telegram-Bot-Api-Secret-Token` of the webhook, `bin2hex(random_bytes(32))`. */
    public ?string $webhook_secret;

    /** Read-only: from getMe, to notice a changed bot (TG-11). */
    public ?int $bot_id;

    /** Read-only: from getMe, without `@`. */
    public ?string $bot_username;

    /** ISO 8601 time of the last successful «Подключить бота», null while not connected. */
    public ?string $connected_at;

    public string $menu_button_text;

    /** Null = the default text from lang/ru. */
    public ?string $welcome_text;

    /** Null = the default text from lang/ru. */
    public ?string $help_text;

    /** Mini App for customers (TM). */
    public bool $miniapp_enabled;

    /** Automatic Mini App sign-in for a linked customer (TM-04). */
    public bool $autologin_enabled;

    /** Status messages to customers in Telegram (TN). */
    public bool $customer_notifications_enabled;

    /** Action buttons under work chat messages (TS-03). */
    public bool $work_chat_buttons_enabled;

    /** Mini App «Рабочее место» for staff (TS-05). */
    public bool $staff_workplace_enabled;

    /** Mini App look: `shop` (shop theme) or `telegram` (Telegram theme colours), TM-07. */
    public string $miniapp_colors;

    /** Seconds an `auth_date` of Telegram `initData` stays valid for a customer / staff session (TM-02). */
    public int $initdata_ttl_customer;

    public int $initdata_ttl_staff;

    /** ISO 8601 time when `t.me/{bot}?startapp=check` worked (TG-03), null = links not verified. */
    public ?string $startapp_verified_at;

    /** Last work chat delivery error (TG-10), null = none. */
    public ?string $work_chat_error;

    /**
     * Saves only the given properties: the settings are re-read first, so a value changed meanwhile by another request (the
     * admin's «Подключить бота», a webhook, a queue job) is not overwritten with this instance's stale copy. spatie's
     * save() rewrites every property, hence the refresh right before it.
     *
     * @param  array<string, mixed>  $fields  property => new value
     */
    public function saveOnly(array $fields): void
    {
        $this->refresh();

        foreach ($fields as $property => $value) {
            $this->{$property} = $value;
        }

        $this->save();
    }

    /**
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return ['bot_token', 'webhook_secret'];
    }

    public static function group(): string
    {
        return 'telegram';
    }
}
