<?php

declare(strict_types=1);

use App\Enums\AgeRestriction;
use App\Models\Category;
use App\Models\Product;
use App\Settings\AgeGateSettings;

/**
 * Configures the age gate for a test (it is disabled by default).
 *
 * @param  array<string, mixed>  $values
 */
function ageGate(array $values = []): AgeGateSettings
{
    setSettings(AgeGateSettings::class, array_merge(['enabled' => true], $values));

    return app(AgeGateSettings::class);
}

/**
 * A visible category with an own age restriction.
 */
function ageCategory(?AgeRestriction $level, ?Category $parent = null, string $name = 'Табак'): Category
{
    return Category::factory()->create(['name' => $name, 'parent_id' => $parent?->id, 'age_restriction' => $level]);
}

/**
 * A visible product in the category.
 *
 * @param  array<string, mixed>  $attributes
 */
function ageProduct(Category $category, array $attributes = []): Product
{
    return Product::factory()->inCategory($category)->create($attributes);
}
