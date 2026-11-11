<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * FL-05, FL-11, FL-14, FL-16: defaults of the filter settings; `attributes_migrated_at` is set by the migration
 * of characteristics from JSON (FL-04).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('catalog.filters_max_per_category', 9);
        $this->migrator->add('catalog.facets_cache_minutes', 10);
        $this->migrator->add('catalog.seo_single_value_min_products', 3);
        $this->migrator->add('catalog.create_attributes_on_import', true);
        $this->migrator->add('catalog.attributes_migrated_at', null);
    }

    public function down(): void
    {
        foreach (['filters_max_per_category', 'facets_cache_minutes', 'seo_single_value_min_products', 'create_attributes_on_import', 'attributes_migrated_at'] as $property) {
            $this->migrator->delete('catalog.'.$property);
        }
    }
};
