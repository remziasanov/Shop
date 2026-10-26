<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Enums\SalesModeSource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Sales\SalesModeResolver;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;

function resolver(): SalesModeResolver
{
    return app(SalesModeResolver::class);
}

it('falls back to the shop default', function (): void {
    $product = Product::factory()->create();
    $result = resolver()->resolve($product);

    expect($result->mode)->toBe(SalesMode::Cart)
        ->and($result->source)->toBe(SalesModeSource::Shop)
        ->and($result->sourceCategory)->toBeNull()
        ->and($result->label())->toBe('Корзина (из настроек магазина)');
});

it('uses the shop default from settings', function (): void {
    $settings = app(SalesSettings::class);
    $settings->default_mode = SalesMode::Showcase;
    $settings->save();

    $product = Product::factory()->create();

    expect(app(SalesModeResolver::class)->resolve($product)->mode)->toBe(SalesMode::Showcase);
});

it('lets the product override everything', function (): void {
    $category = Category::factory()->salesMode(SalesMode::Reservation)->create();
    $product = Product::factory()->inCategory($category)->salesMode(SalesMode::Both)->create();
    $result = resolver()->resolve($product);

    expect($result->mode)->toBe(SalesMode::Both)->and($result->source)->toBe(SalesModeSource::Product);
});

it('inherits from the nearest category through several levels', function (): void {
    $root = Category::factory()->salesMode(SalesMode::Reservation)->create(['name' => 'Мебель']);
    $child = Category::factory()->create(['parent_id' => $root->id]);
    $grand = Category::factory()->create(['parent_id' => $child->id]);
    $product = Product::factory()->inCategory($grand)->create();

    $result = resolver()->resolve($product);

    expect($result->mode)->toBe(SalesMode::Reservation)
        ->and($result->source)->toBe(SalesModeSource::Category)
        ->and($result->sourceCategory->id)->toBe($root->id)
        ->and($result->label())->toBe('Резерв (из категории “Мебель”)');

    // A closer category wins over the ancestor.
    $child->update(['sales_mode' => SalesMode::Inquiry]);
    $result = resolver()->resolve($product->fresh());

    expect($result->mode)->toBe(SalesMode::Inquiry)->and($result->sourceCategory->id)->toBe($child->id);
});

it('forces inquiry for price on request', function (): void {
    $product = Product::factory()->priceOnRequest()->salesMode(SalesMode::Cart)->create();
    $result = resolver()->resolve($product);

    expect($result->mode)->toBe(SalesMode::Inquiry)
        ->and($result->source)->toBe(SalesModeSource::PriceOnRequest)
        ->and($result->label())->toBe('Заявка (цена по запросу)');
});

it('does not query the database once the tree is cached', function (): void {
    $root = Category::factory()->salesMode(SalesMode::Both)->create();
    $product = Product::factory()->inCategory($root)->create();
    $resolver = resolver();
    $resolver->resolve($product);

    DB::enableQueryLog();
    $resolver->resolve($product);
    $resolver->resolve($product);

    expect(DB::getQueryLog())->toBeEmpty();
});

it('resolves unsaved values for form hints', function (): void {
    $root = Category::factory()->salesMode(SalesMode::Reservation)->create();

    expect(resolver()->resolveFor(null, $root->id)->mode)->toBe(SalesMode::Reservation)
        ->and(resolver()->resolveFor(SalesMode::Cart, $root->id)->mode)->toBe(SalesMode::Cart)
        ->and(resolver()->resolveFor(null, null)->source)->toBe(SalesModeSource::Shop);
});
