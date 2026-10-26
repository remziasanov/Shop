<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\SlugService;

it('transliterates cyrillic names', function (): void {
    expect(app(SlugService::class)->unique('products', 'Кресло офисное «Комфорт» 2.0'))->toBe('kreslo-ofisnoe-komfort-20');
});

it('adds -2, -3 suffixes on collisions', function (): void {
    $category = Category::factory()->create();
    $a = Product::factory()->inCategory($category)->create(['name' => 'Стул']);
    $b = Product::factory()->inCategory($category)->create(['name' => 'Стул']);
    $c = Product::factory()->inCategory($category)->create(['name' => 'Стул']);

    expect([$a->slug, $b->slug, $c->slug])->toBe(['stul', 'stul-2', 'stul-3']);
});

it('ignores the record being edited and soft-deleted rows count as taken', function (): void {
    $category = Category::factory()->create();
    $a = Product::factory()->inCategory($category)->create(['name' => 'Стул']);
    $b = Product::factory()->inCategory($category)->create(['name' => 'Стул']);

    expect(app(SlugService::class)->unique('products', 'Стул', $b->id))->toBe('stul-2')
        ->and(app(SlugService::class)->unique('products', 'Стул', $a->id))->toBe('stul');

    $a->delete();

    expect(app(SlugService::class)->unique('products', 'Стул'))->toBe('stul-3');
});

it('normalizes a manual slug with the same rules', function (): void {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create(['name' => 'Любое', 'slug' => 'Мой Слаг  Тут!']);

    expect($product->slug)->toBe('moy-slag-tut');
});

it('generates slugs for categories and falls back for symbol-only names', function (): void {
    $one = Category::factory()->create(['name' => 'Мебель']);
    $two = Category::factory()->create(['name' => 'Мебель']);
    $odd = Category::factory()->create(['name' => '!!!']);

    expect([$one->slug, $two->slug, $odd->slug])->toBe(['mebel', 'mebel-2', 'category']);
});
