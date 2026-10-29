<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // CG-21: two independent toggles for new wholesale applications; no other customer events notify the shop.
        $this->migrator->add('notifications.notify_wholesale_email', true);
        $this->migrator->add('notifications.notify_wholesale_telegram', true);
    }
};
