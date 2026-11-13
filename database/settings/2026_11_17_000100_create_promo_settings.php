<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * PM-12: give a promo code back when its order is cancelled (default on, also for one-time codes).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('promo.release_on_cancel', true);
    }

    public function down(): void
    {
        $this->migrator->delete('promo.release_on_cancel');
    }
};
