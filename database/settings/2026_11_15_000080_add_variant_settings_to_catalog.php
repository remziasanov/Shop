<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * VR-02, VR-16: the variant limit per card and the import switch that creates missing options and values.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('catalog.max_variants_per_product', 100);
        $this->migrator->add('catalog.create_options_on_import', true);
    }

    public function down(): void
    {
        $this->migrator->delete('catalog.max_variants_per_product');
        $this->migrator->delete('catalog.create_options_on_import');
    }
};
