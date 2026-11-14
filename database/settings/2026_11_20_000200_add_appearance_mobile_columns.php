<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * MB-07: «Колонок на телефоне» (1 or 2) of the product grid, 2 by default.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('appearance.mobile_columns', 2);
    }

    public function down(): void
    {
        $this->migrator->delete('appearance.mobile_columns');
    }
};
