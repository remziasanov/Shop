<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Catalog\CatalogVersion;

/**
 * Helpers of the storefront filter tests (stage 10, part B).
 */
function facetAttr(string $slug, AttributeType $type = AttributeType::List, array $attributes = []): Attribute
{
    return Attribute::factory()->create($attributes + [
        'name' => ucfirst($slug),
        'slug' => $slug,
        'type' => $type,
        'unit' => $type === AttributeType::Number ? 'Вт' : null,
    ]);
}

function facetValue(Attribute $attribute, string $slug, ?string $label = null, int $sort = 0): AttributeValue
{
    return AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'slug' => $slug, 'value' => $label ?? ucfirst($slug), 'sort_order' => $sort]);
}

/**
 * Own filter set of the category, in the given order.
 *
 * @param  list<Attribute>  $attributes
 */
function facetSet(Category $category, array $attributes, bool $expanded = false): void
{
    foreach (array_values($attributes) as $position => $attribute) {
        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $attribute->id, 'sort_order' => ($position + 1) * 10, 'is_expanded' => $expanded]);
    }

    CatalogVersion::bump();
}

/**
 * A storefront product with values: `[[Attribute, AttributeValue|list<AttributeValue>|string|bool], …]` (a string is a
 * number, a bool a boolean).
 *
 * @param  list<array{0: Attribute, 1: mixed}>  $values
 * @param  array<string, mixed>  $attributes
 */
function facetProduct(Category $category, array $values = [], array $attributes = []): Product
{
    $product = Product::factory()->inCategory($category)->create($attributes + ['brand' => null, 'stock_qty' => 10]);

    foreach ($values as [$attribute, $value]) {
        foreach (is_array($value) ? $value : [$value] as $item) {
            ProductAttributeValue::query()->create([
                'product_id' => $product->id,
                'attribute_id' => $attribute->id,
                'attribute_value_id' => $item instanceof AttributeValue ? $item->id : null,
                'value_number' => is_string($item) ? $item : null,
                'value_bool' => is_bool($item) ? $item : null,
            ]);
        }
    }

    return $product;
}

/**
 * Ids of the products the listing of the category shows for the query string, in listing order.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function facetListed(Category $category, array $query = []): array
{
    $response = test()->withoutVite()->followingRedirects()->get(route('catalog.category', $category->slug).($query === [] ? '' : '?'.http_build_query($query)))->assertOk();

    return array_map(
        fn ($card): int => $card->product->id,
        $response->viewData('cards'),
    );
}
