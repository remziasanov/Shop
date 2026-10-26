<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('notifications.notify_emails', []);
        $this->migrator->add('notifications.telegram_enabled', false);
        // Secret: never seeded with a real value; stored encrypted once set.
        $this->migrator->addEncrypted('notifications.telegram_bot_token', null);
        $this->migrator->add('notifications.telegram_chat_id', null);
        $this->migrator->add('notifications.notify_shop_email', true);
        $this->migrator->add('notifications.notify_shop_telegram', true);
        $this->migrator->add('notifications.notify_customer_new', true);
        $this->migrator->add('notifications.notify_customer_status', true);
        $this->migrator->add('notifications.notify_reservation_expiring', true);
        $this->migrator->add('notifications.notify_reservation_expired', true);
    }
};
