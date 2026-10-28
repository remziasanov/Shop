<?php

declare(strict_types=1);

use App\Enums\AgeRestriction;
use App\Models\Product;
use App\Services\Age\AgeGate;
use App\Services\Age\AgeRestrictionResolver;
use App\Services\Catalog\CategoryTree;
use Illuminate\Support\Facades\DB;

it('has no restriction by default (AG-01)', function () {
    $product = ageProduct(ageCategory(null));

    expect(app(AgeRestrictionResolver::class)->forProduct($product))->toBeNull();
});

it('inherits the restriction from the category itself, then from its parents (AG-01)', function () {
    $root = ageCategory(AgeRestriction::Age18, name: 'Табак');
    $middle = ageCategory(null, $root, 'Трубочный');
    $leaf = ageCategory(null, $middle, 'Смеси');
    $resolver = app(AgeRestrictionResolver::class);

    expect($resolver->forCategory($leaf))->toBe(AgeRestriction::Age18)
        ->and($resolver->forProduct(ageProduct($leaf)))->toBe(AgeRestriction::Age18)
        ->and($resolver->categorySource($leaf->id)?->name)->toBe('Табак');
});

it('lets the nearest category win over the farther one (AG-01)', function () {
    $root = ageCategory(AgeRestriction::Age18);
    $child = ageCategory(AgeRestriction::Age16, $root, 'Подарочные наборы');

    expect(app(AgeRestrictionResolver::class)->forProduct(ageProduct($child)))->toBe(AgeRestriction::Age16);
});

it('lets the own value of a product win over its category: 16+ in an 18+ category (AG-01)', function () {
    $category = ageCategory(AgeRestriction::Age18);
    $product = ageProduct($category, ['age_restriction' => AgeRestriction::Age16]);
    $resolver = app(AgeRestrictionResolver::class);

    expect($resolver->forProduct($product))->toBe(AgeRestriction::Age16)
        ->and($resolver->describe($product)['source'])->toBe('product');
});

it('describes where an inherited level comes from (AG-12)', function () {
    $product = ageProduct(ageCategory(AgeRestriction::Age18, name: 'Табак'));
    $described = app(AgeRestrictionResolver::class)->describe($product);

    expect($described['level'])->toBe(AgeRestriction::Age18)
        ->and($described['source'])->toBe('category')
        ->and($described['category']?->name)->toBe('Табак');
});

it('returns the strictest level of several products', function () {
    $resolver = app(AgeRestrictionResolver::class);
    $plain = ageProduct(ageCategory(null, name: 'Мебель'));
    $sixteen = ageProduct(ageCategory(AgeRestriction::Age16, name: 'Пиво'));
    $eighteen = ageProduct(ageCategory(null, name: 'Прочее'), ['age_restriction' => AgeRestriction::Age18]);

    expect($resolver->strictest([$plain]))->toBeNull()
        ->and($resolver->strictest([$plain, $sixteen]))->toBe(AgeRestriction::Age16)
        ->and($resolver->strictest([$plain, $sixteen, $eighteen]))->toBe(AgeRestriction::Age18);
});

it('resolves a whole list from the cached tree without a single query (AG-01)', function () {
    $category = ageCategory(AgeRestriction::Age18);
    $child = ageCategory(null, $category, 'Дочерняя');
    ageProduct($child);
    ageProduct($child);
    $products = Product::query()->get();
    $resolver = app(AgeRestrictionResolver::class);
    app(CategoryTree::class)->nodes();

    DB::enableQueryLog();
    $levels = $products->map(fn (Product $product) => $resolver->forProduct($product));

    expect(DB::getQueryLog())->toBe([])
        ->and($levels->every(fn ($level) => $level === AgeRestriction::Age18))->toBeTrue();
});

it('sees a category edit through the tree cache (AG-01)', function () {
    $category = ageCategory(null);
    $product = ageProduct($category);
    $resolver = app(AgeRestrictionResolver::class);

    expect($resolver->forProduct($product))->toBeNull();

    $category->update(['age_restriction' => AgeRestriction::Age16]);

    expect($resolver->forProduct($product))->toBe(AgeRestriction::Age16);
});

it('knows whether the shop has restricted products and drops the flag when products change (AG-03)', function () {
    ageGate();
    $gate = app(AgeGate::class);
    $category = ageCategory(null);
    $product = ageProduct($category);

    expect($gate->hasRestricted())->toBeFalse();

    $product->update(['age_restriction' => AgeRestriction::Age18]);

    expect($gate->hasRestricted())->toBeTrue();

    $product->update(['is_active' => false]);

    expect($gate->hasRestricted())->toBeFalse();

    $category->update(['age_restriction' => AgeRestriction::Age16]);
    $product->update(['is_active' => true, 'age_restriction' => null]);

    expect($gate->hasRestricted())->toBeTrue();
});
