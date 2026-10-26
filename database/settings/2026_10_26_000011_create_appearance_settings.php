<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('appearance.primary_color', '#2563eb');
        $this->migrator->add('appearance.accent_color', '#f59e0b');
        $this->migrator->add('appearance.grid_columns', 4);
        $this->migrator->add('appearance.per_page', 24);
        $this->migrator->add('appearance.placeholder_image', null);
    }
};
