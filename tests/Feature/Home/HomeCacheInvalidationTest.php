<?php

declare(strict_types=1);

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use App\Services\Home\HomePageRenderer;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Stock\StockService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Models fire events that drop the cache, so the cache is filled after the fixtures exist. */
function fillHomeCache(): void
{
    Cache::put(HomePageRenderer::CACHE_KEY, ['html' => 'cached', 'preload' => []], 600);
}

it('drops the cached home page after a raw SQL price recalculation', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);
    DB::table('products')->where('id', $product->id)->update(['final_price' => '1.00']);

    fillHomeCache();
    app(ProductPriceUpdater::class)->recalculate([$product->id]);

    expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
});

it('keeps the cached home page when a recalculation changes nothing', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);

    fillHomeCache();
    app(ProductPriceUpdater::class)->recalculate([$product->id]);

    expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeTrue();
});

it('drops the cached home page when StockService changes the stock quietly', function (): void {
    $product = Product::factory()->create(['stock_qty' => 5]);

    fillHomeCache();
    app(StockService::class)->setStock($product, 9);

    expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
});

it('drops the cached home page after a bulk product action', function (): void {
    $this->actingAs(User::factory()->manager()->create());
    $products = Product::factory()->count(2)->create(['is_active' => true]);

    fillHomeCache();

    Livewire::test(ListProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('deactivate')->table()->bulk());

    expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
});
