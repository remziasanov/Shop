<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Catalog\AttributeSyncService;
use Illuminate\Support\Facades\DB;

/**
 * 20.2: the JSON display cache `products.attributes` is rebuilt from the tables.
 */
function attrSync(): AttributeSyncService
{
    return app(AttributeSyncService::class);
}

function attrAddList(Product $product, Attribute $attribute, array $values): void
{
    foreach ($values as $position => $text) {
        $value = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => $text, 'sort_order' => $position]);
        ProductAttributeValue::factory()->create(['product_id' => $product->id, 'attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]);
    }
}

it('writes name/value pairs: units for numbers, joined lists, да/нет, in dictionary order', function (): void {
    $product = Product::factory()->create(['attributes' => null]);
    $color = Attribute::factory()->create(['name' => 'Цвет', 'sort_order' => 30]);
    $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность', 'sort_order' => 10]);
    $dry = Attribute::factory()->boolean()->create(['name' => 'Водонепроницаемый', 'sort_order' => 20]);
    $weight = Attribute::factory()->number()->create(['name' => 'Вес', 'sort_order' => 40]);
    attrAddList($product, $color, ['Красный', 'Синий']);
    ProductAttributeValue::factory()->create(['product_id' => $product->id, 'attribute_id' => $power->id, 'attribute_value_id' => null, 'value_number' => '750.5000']);
    ProductAttributeValue::factory()->create(['product_id' => $product->id, 'attribute_id' => $dry->id, 'attribute_value_id' => null, 'value_bool' => false]);
    ProductAttributeValue::factory()->create(['product_id' => $product->id, 'attribute_id' => $weight->id, 'attribute_value_id' => null, 'value_number' => '2.0000']);

    attrSync()->syncProduct($product, force: true);

    expect($product->fresh()->specs())->toBe([
        ['name' => 'Мощность', 'value' => '750.5 Вт'],
        ['name' => 'Водонепроницаемый', 'value' => 'нет'],
        ['name' => 'Цвет', 'value' => 'Красный, Синий'],
        ['name' => 'Вес', 'value' => '2'],
    ])->and($product->specs())->toBe($product->fresh()->specs());
});

it('leaves out inactive attributes and orders list values by dictionary order', function (): void {
    $product = Product::factory()->create(['attributes' => null]);
    $color = Attribute::factory()->create(['name' => 'Цвет']);
    $hidden = Attribute::factory()->inactive()->create(['name' => 'Скрытая']);
    attrAddList($product, $color, ['Первый', 'Второй']);
    attrAddList($product, $hidden, ['x']);

    attrSync()->syncProduct($product, force: true);

    expect($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Первый, Второй']]);
});

it('keeps Product::specs() reading the JSON', function (): void {
    $product = Product::factory()->create(['attributes' => [['name' => 'Материал', 'value' => 'Дерево']]]);

    expect($product->specs())->toBe([['name' => 'Материал', 'value' => 'Дерево']]);
});

it('does not wipe JSON-only products before the migration is finished, unless forced', function (): void {
    $jsonOnly = Product::factory()->create(['attributes' => [['name' => 'Материал', 'value' => 'Дерево']]]);

    expect(attrSync()->syncProducts([$jsonOnly->id]))->toBe(0)
        ->and($jsonOnly->fresh()->specs())->toBe([['name' => 'Материал', 'value' => 'Дерево']]);

    attrSync()->syncProducts([$jsonOnly->id], force: true);

    expect($jsonOnly->fresh()->specs())->toBe([]);
});

it('clears JSON of products without rows once the migration is finished', function (): void {
    attrMigrated();
    $product = Product::factory()->create(['attributes' => [['name' => 'Материал', 'value' => 'Дерево']]]);

    attrSync()->syncProducts([$product->id]);

    expect(DB::table('products')->where('id', $product->id)->value('attributes'))->toBeNull();
});

it('rebuilds many products in chunks and reports how many changed', function (): void {
    attrMigrated();
    $color = Attribute::factory()->create(['name' => 'Цвет']);
    $products = Product::factory()->count(3)->create(['attributes' => null]);

    foreach ($products as $product) {
        attrAddList($product, $color, ['Красный '.$product->id]);
    }

    $seen = [];
    $changed = attrSync()->rebuildAll(function (int $processed) use (&$seen): void {
        $seen[] = $processed;
    });

    expect($changed)->toBe(3)
        ->and($seen)->toBe([3])
        ->and($products[2]->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный '.$products[2]->id]])
        // Second run: nothing to change.
        ->and(attrSync()->rebuildAll())->toBe(0);
});

it('is exposed by the artisan command', function (): void {
    attrMigrated();
    $color = Attribute::factory()->create(['name' => 'Цвет']);
    $product = Product::factory()->create(['attributes' => null]);
    attrAddList($product, $color, ['Красный']);

    $this->artisan('shop:rebuild-attribute-cache')->assertSuccessful();

    expect($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный']]);
});

it('builds the content for several products with one query', function (): void {
    $color = Attribute::factory()->create(['name' => 'Цвет']);
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    attrAddList($a, $color, ['Красный']);
    attrAddList($b, $color, ['Синий']);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $specs = attrSync()->specsFor([$a->id, $b->id]);

    expect($queries)->toBe(1)
        ->and($specs[$a->id][0]['value'])->toBe('Красный')
        ->and($specs[$b->id][0]['value'])->toBe('Синий');
});
