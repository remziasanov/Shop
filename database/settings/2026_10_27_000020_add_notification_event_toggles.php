<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Which kinds of new requests notify the shop (both email and Telegram), TZ section 9.
        $this->migrator->add('notifications.notify_event_order', true);
        $this->migrator->add('notifications.notify_event_reservation', true);
        $this->migrator->add('notifications.notify_event_inquiry', true);
    }
};
