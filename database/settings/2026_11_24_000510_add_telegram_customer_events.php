<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * TN-02: the shop-side switch of every event reported to a customer in Telegram. All on, except «Выгрузка готова».
 */
return new class extends SettingsMigration
{
    private const EVENTS = [
        'order_created' => true,
        'status_changed' => true,
        'reservation_expiring' => true,
        'reservation_expired' => true,
        'items_changed' => true,
        'wholesale_decision' => true,
        'export_ready' => false,
    ];

    public function up(): void
    {
        foreach (self::EVENTS as $event => $default) {
            $this->migrator->add('telegram.notify_'.$event, $default);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::EVENTS) as $event) {
            $this->migrator->delete('telegram.notify_'.$event);
        }
    }
};
