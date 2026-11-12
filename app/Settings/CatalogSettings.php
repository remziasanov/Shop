<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * «Каталог и фильтры» (FL-05, FL-11, FL-14, FL-16, FL-04): settings of the characteristic filters.
 */
class CatalogSettings extends Settings
{
    /** FL-05: configurable filters per category (the facet query budget is 10, FL-10). */
    public int $filters_max_per_category;

    /** FL-11: lifetime of the facets cache, minutes. */
    public int $facets_cache_minutes;

    /** FL-14: a single-value filter page goes to the sitemap from this number of products. */
    public int $seo_single_value_min_products;

    /** FL-16: the import creates characteristics that are missing in the dictionary. */
    public bool $create_attributes_on_import;

    /** FL-04: when the migration from the JSON characteristics finished (ISO 8601); null = not finished. Not edited by hand. */
    public ?string $attributes_migrated_at;

    /** VR-02: the most variants one card may have. */
    public int $max_variants_per_product;

    /** VR-16: the import creates options and option values that are missing in the dictionary. */
    public bool $create_options_on_import;

    public static function group(): string
    {
        return 'catalog';
    }
}
