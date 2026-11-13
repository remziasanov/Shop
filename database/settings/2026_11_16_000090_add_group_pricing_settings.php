<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * GP-19: the rounding step for group prices, the next tier hint and its threshold (GP-11).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('pricing.group_prices_rounding', true);
        $this->migrator->add('pricing.tier_hint_enabled', true);
        $this->migrator->add('pricing.tier_hint_percent', 50);
        $this->migrator->add('pricing.tier_hint_qty', 20);
    }

    public function down(): void
    {
        $this->migrator->delete('pricing.group_prices_rounding');
        $this->migrator->delete('pricing.tier_hint_enabled');
        $this->migrator->delete('pricing.tier_hint_percent');
        $this->migrator->delete('pricing.tier_hint_qty');
    }
};
