<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('pricing.rounding_step', 0);
        $this->migrator->add('pricing.rounding_direction', 'nearest');
        $this->migrator->add('pricing.psychological_price', false);
        $this->migrator->add('pricing.rounding_scope', 'changed');
        $this->migrator->add('pricing.show_old_price', true);
        $this->migrator->add('pricing.show_discount_percent', true);
    }
};
