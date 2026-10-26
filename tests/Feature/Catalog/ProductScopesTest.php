<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;

it('active() hides inactive products', function (): void {
    $category = Category::factory()->create();
    $visible = Product::factory()->inCategory($category)->create();
    Product::factory()->inCategory($category)->inactive()->create();

    expect(Product::active()->pluck('id')->all())->toBe([$visible->id]);
});

it('active() hides products of inactive categories and of descendants of inactive ones (SF-16)', function (): void {
    $root = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $root->id]);
    $other = Category::factory()->create();

    $inRoot = Product::factory()->inCategory($root)->create();
    $inChild = Product::factory()->inCategory($child)->create();
    $inOther = Product::factory()->inCategory($other)->create();

    expect(Product::active()->pluck('id')->all())->toEqualCanonicalizing([$inRoot->id, $inChild->id, $inOther->id]);

    $root->update(['is_active' => false]);

    expect(Product::active()->pluck('id')->all())->toBe([$inOther->id]);
});

it('fills search_text from name, sku and brand', function (): void {
    $product = Product::factory()->create(['name' => 'Ёлка Новогодняя', 'sku' => 'EL-001', 'brand' => 'Север']);

    expect($product->search_text)->toBe('елка новогодняя el-001 север');

    $product->update(['name' => 'Стул']);
    expect($product->fresh()->search_text)->toBe('стул el-001 север');
});

it('searches case-insensitively with yo folding and extra spaces', function (): void {
    $match = Product::factory()->create(['name' => 'Ёлка искусственная']);
    Product::factory()->create(['name' => 'Стол']);

    expect(Product::search('  ЕЛКА  ')->pluck('id')->all())->toBe([$match->id])
        ->and(Product::search('ёлка')->pluck('id')->all())->toBe([$match->id]);
});

it('requires all words (AND)', function (): void {
    $both = Product::factory()->create(['name' => 'Кресло офисное']);
    Product::factory()->create(['name' => 'Кресло садовое']);
    Product::factory()->create(['name' => 'Стол офисный']);

    expect(Product::search('кресло офисное')->pluck('id')->all())->toBe([$both->id])
        ->and(Product::search('офисное кресло')->pluck('id')->all())->toBe([$both->id]);
});

it('finds by sku and brand and ranks an exact sku first', function (): void {
    $other = Product::factory()->create(['name' => 'Лампа AB-100 плюс', 'sku' => 'ZZ-1', 'brand' => 'Аврора']);
    $exact = Product::factory()->create(['name' => 'Лампа', 'sku' => 'ab-100', 'brand' => 'Аврора']);
    $branded = Product::factory()->create(['name' => 'Стул', 'sku' => 'QQ-2', 'brand' => 'Нордик']);

    expect(Product::search('AB-100')->pluck('id')->all())->toBe([$exact->id, $other->id])
        ->and(Product::search('нордик')->pluck('id')->all())->toBe([$branded->id]);
});

it('escapes LIKE wildcards in the query', function (): void {
    $percent = Product::factory()->create(['name' => 'Скидка 50% на всё']);
    Product::factory()->create(['name' => 'Скидка 500 на всё']);
    $underscore = Product::factory()->create(['name' => 'Код a_b']);
    Product::factory()->create(['name' => 'Код axb']);

    expect(Product::search('50%')->pluck('id')->all())->toBe([$percent->id])
        ->and(Product::search('a_b')->pluck('id')->all())->toBe([$underscore->id])
        ->and(Product::search('%')->pluck('id')->all())->toBe([$percent->id])
        ->and(Product::search('!')->count())->toBe(0);
});

it('does not filter on an empty query', function (): void {
    Product::factory()->count(2)->create();

    expect(Product::search('   ')->count())->toBe(2);
});

it('exposes derived helpers', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00', 'compare_at_price' => '1250.00', 'stock_qty' => 10, 'attributes' => [['name' => 'Цвет', 'value' => 'Белый'], ['name' => '', 'value' => 'x']]]);
    $product->forceFill(['reserved_qty' => 3])->save();

    expect($product->final_price)->toBe('1000.00')
        ->and($product->display_old_price)->toBe('1250.00')
        ->and($product->discountPercent())->toBe(20)
        ->and($product->availableQty())->toBe(7)
        ->and($product->specs())->toBe([['name' => 'Цвет', 'value' => 'Белый']])
        ->and(Product::factory()->untracked()->create()->availableQty())->toBeNull();
});

it('recomputes prices on save', function (): void {
    $product = Product::factory()->create(['base_price' => '500.00']);
    expect($product->final_price)->toBe('500.00')->and($product->price_calculated_at)->not->toBeNull();

    $product->update(['fixed_price' => '420.50']);
    expect($product->fresh()->final_price)->toBe('420.50');

    $product->update(['fixed_price' => null, 'compare_at_price' => '800.00']);
    $fresh = $product->fresh();
    expect($fresh->final_price)->toBe('500.00')->and($fresh->display_old_price)->toBe('800.00');
});

it('loads the main image and ordered images', function (): void {
    $product = Product::factory()->create();
    $second = $product->images()->create(['path_large' => 'a', 'path_medium' => 'b', 'path_small' => 'c', 'sort_order' => 2]);
    $first = $product->images()->create(['path_large' => 'a', 'path_medium' => 'b', 'path_small' => 'c', 'sort_order' => 1]);

    $loaded = Product::with(['mainImage', 'images'])->find($product->id);

    expect($loaded->mainImage->id)->toBe($first->id)
        ->and($loaded->images->pluck('id')->all())->toBe([$first->id, $second->id]);
});
