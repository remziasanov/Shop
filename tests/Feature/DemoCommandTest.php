<?php

declare(strict_types=1);

use App\Models\Attribute;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\CustomerGroup;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Demo\DemoWholesaleSeeder;
use App\Services\Pricing\PriceTierService;
use Illuminate\Support\Facades\Storage;

it('creates a 3-level category tree and about 50 products without images', function (): void {
    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    expect(Product::query()->count())->toBeGreaterThanOrEqual(45)
        ->and(Category::query()->count())->toBeBetween(12, 20)
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

it('adds the data of version 2.0: wholesale tiers, variants, filters, a promo code and a promo link, idempotently', function (): void {
    $this->withoutVite();
    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    $snapshot = fn (): array => [
        'tiers' => ProductPriceTier::query()->count(),
        'history' => PriceHistory::query()->count(),
        'variants' => ProductVariant::query()->count(),
        'filters' => CategoryFilter::query()->count(),
        'attributes' => Attribute::query()->count(),
        'options' => OptionValue::query()->count(),
        'codes' => PromoCode::query()->count(),
        'links' => PromoLink::query()->count(),
        'products' => Product::query()->count(),
    ];
    $first = $snapshot();
    $opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $mug = Product::query()->where('name', 'Кружка керамическая Утро')->firstOrFail();

    expect(app(PriceTierService::class)->rows($mug, $opt))->toBe([
        ['min_qty' => 1, 'price' => '351.00'],
        ['min_qty' => 10, 'price' => '312.00'],
        ['min_qty' => 50, 'price' => '273.00'],
    ]);

    $card = Product::query()->where('sku', DemoWholesaleSeeder::SHIRT_SKU)->firstOrFail();
    expect($card->has_variants)->toBeTrue()
        ->and(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(12)
        ->and(OptionType::query()->whereIn('name', ['Цвет', 'Размер'])->count())->toBe(2)
        ->and(PromoCode::query()->where('code', 'DEMO10')->exists())->toBeTrue()
        ->and(PromoLink::query()->where('slug', 'demo-futbolka')->firstOrFail()->promo_code_id)->toBe(PromoCode::query()->where('code', 'DEMO10')->value('id'))
        ->and(Attribute::query()->where('name', 'Мощность')->exists())->toBeTrue()
        ->and(CategoryFilter::query()->count())->toBeGreaterThan(5)
        ->and(app(AttributeMigrationState::class)->isMigrated())->toBeTrue();

    // The wholesale category is hidden from the guest, not from the wholesaler.
    $this->get('/catalog/demo-opt-nabory')->assertNotFound();
    $this->get('/product/'.$card->slug)->assertOk();

    $this->artisan('shop:demo', ['--no-images' => true])->assertSuccessful();

    expect($snapshot())->toBe($first);
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
