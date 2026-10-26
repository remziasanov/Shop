<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

it('creates a 3-level category tree and about 50 products without images', function (): void {
    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    expect(Product::query()->count())->toBeGreaterThanOrEqual(45)
        ->and(Category::query()->count())->toBeBetween(12, 16)
        ->and(Category::query()->max('depth'))->toBe(3)
        ->and(Category::query()->where('slug', 'demo-mebel')->first()?->sales_mode?->value)->toBe('reservation')
        ->and(ProductImage::query()->count())->toBe(0)
        ->and(Product::query()->whereNull('stock_qty')->exists())->toBeTrue()
        ->and(Product::query()->where('stock_qty', 0)->exists())->toBeTrue()
        ->and(Product::query()->where('price_on_request', true)->exists())->toBeTrue();
});

it('is idempotent', function (): void {
    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();
    $products = Product::query()->count();
    $categories = Category::query()->count();

    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    expect(Product::query()->count())->toBe($products)
        ->and(Category::query()->count())->toBe($categories);
});

it('refuses to run in production without --force', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('shop:demo', ['--no-images' => true])->assertFailed();
    expect(Product::query()->count())->toBe(0);

    $this->artisan('shop:demo', ['--no-images' => true, '--force' => true])->assertSuccessful();
    expect(Product::query()->count())->toBeGreaterThanOrEqual(45);
});

it('removes only demo data with --fresh', function (): void {
    $category = Category::factory()->create();
    $own = Product::factory()->inCategory($category)->create(['sku' => 'OWN-0001']);

    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();
    $this->artisan('shop:demo', ['--no-images' => true, '--fresh' => true])->assertSuccessful();

    expect(Product::query()->where('sku', 'like', 'DEMO-%')->count())->toBeGreaterThanOrEqual(45)
        ->and(Product::query()->whereKey($own->id)->exists())->toBeTrue()
        ->and(Category::query()->whereKey($category->id)->exists())->toBeTrue();

    $this->artisan('shop:demo', ['--no-images' => true, '--fresh' => true])->assertSuccessful();
    expect(Product::query()->whereKey($own->id)->exists())->toBeTrue();
});

it('generates image files for products and does not duplicate them', function (): void {
    Storage::fake('public');

    $this->artisan('shop:demo', ['--limit' => 3])->assertSuccessful();

    $images = ProductImage::query()->get();
    expect(Product::query()->count())->toBe(3)
        ->and($images)->not->toBeEmpty();

    foreach ($images as $image) {
        Storage::disk('public')->assertExists($image->path_large);
    }

    $count = $images->count();
    $this->artisan('shop:demo', ['--limit' => 3])->assertSuccessful();
    expect(ProductImage::query()->count())->toBe($count);
});
