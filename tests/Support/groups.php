<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CatalogVersion;
use Tests\TestCase;

/**
 * The «Гость» group created by the migration.
 */
function guestGroup(): CustomerGroup
{
    return CustomerGroup::query()->where('code', 'guest')->firstOrFail();
}

/**
 * Changes the settings of the «Гость» group (the viewer of every storefront request in stage 8).
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<Category>  $categories
 */
function configureGuest(array $attributes, ?CategoryAccessMode $mode = null, array $categories = []): CustomerGroup
{
    $group = guestGroup();

    if ($mode !== null) {
        $attributes['category_access_mode'] = $mode;
    }

    $group->fill($attributes)->save();
    $group->categories()->sync(array_map(fn (Category $category): int => $category->id, $categories));
    CatalogVersion::bump();

    return $group->fresh();
}

/**
 * Makes the test request look at the storefront as the group (the staff preview session, CG-18).
 */
function previewAs(TestCase $test, CustomerGroup $group): void
{
    $test->actingAs(staffWith(['customer_groups.view']));
    $test->withSession([ViewerContext::PREVIEW_KEY => $group->id]);
}

/**
 * A product of the category that can be bought, with a stable name.
 *
 * @param  array<string, mixed>  $attributes
 */
function groupProduct(Category $category, string $name, array $attributes = []): Product
{
    return Product::factory()->inCategory($category)->create(array_merge([
        'name' => $name,
        'base_price' => '1000.00',
        'stock_qty' => 10,
        'reserved_qty' => 0,
        'sales_mode' => SalesMode::Both,
    ], $attributes));
}
