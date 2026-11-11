<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Catalog\AttributeService;
use App\Settings\CatalogSettings;

/**
 * Helpers of the characteristic tests (stage 10).
 */
function attrService(): AttributeService
{
    return app(AttributeService::class);
}

function attrMigrated(): void
{
    $settings = app(CatalogSettings::class);
    $settings->attributes_migrated_at = now()->toIso8601String();
    $settings->save();
}

function attrValuesOf(Product $product, Attribute $attribute): array
{
    return ProductAttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $attribute->id)->get()->all();
}
