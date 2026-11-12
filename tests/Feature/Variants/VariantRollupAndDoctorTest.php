<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\System\EnvironmentChecker;
use App\Services\Variants\VariantAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->size = optionWith('Размер', ['S', 'M']);
    $this->card = variantCard(['base_price' => '1000.00'], $this->size);
    variantOf($this->card, [$this->size->values[0]], ['stock_qty' => 5]);
    variantOf($this->card, [$this->size->values[1]], ['stock_qty' => 3]);
});

function doctorCheck(string $key): object
{
    return collect(app(EnvironmentChecker::class)->run())->firstWhere('key', $key);
}

it('reports no discrepancy for consistent aggregates', function (): void {
    $this->artisan('shop:variants-rollup')->expectsOutputToContain('Расхождений агрегатов исправлено: 0')->assertSuccessful();

    expect(doctorCheck('variant_aggregates')->status)->toBe(CheckStatus::Ok);
});

it('logs and fixes a drifted aggregate (VR-06)', function (): void {
    Log::spy();
    DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => 99, 'reserved_qty' => 4]);

    expect(doctorCheck('variant_aggregates')->status)->toBe(CheckStatus::Warning);

    $this->artisan('shop:variants-rollup')->expectsOutputToContain('Расхождений агрегатов исправлено: 2')->assertSuccessful();

    expect($this->card->fresh()->stock_qty)->toBe(8)->and($this->card->fresh()->reserved_qty)->toBe(0);
    Log::shouldHaveReceived('warning')->with('Variant aggregate discrepancy fixed', Mockery::on(
        fn (array $row): bool => $row['product_id'] === $this->card->id && $row['field'] === 'stock_qty' && $row['stored'] === '99' && $row['expected'] === '8',
    ))->once();

    expect(doctorCheck('variant_aggregates')->status)->toBe(CheckStatus::Ok);
});

it('catches a NULL aggregate that should be a sum and a sum that should be NULL', function (): void {
    DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => null]);
    expect(collect(app(VariantAggregator::class)->discrepancies())->pluck('field')->all())->toBe(['stock_qty']);

    $this->artisan('shop:variants-rollup')->assertSuccessful();
    expect($this->card->fresh()->stock_qty)->toBe(8);

    DB::table('product_variants')->where('product_id', $this->card->id)->update(['stock_qty' => null]);
    $this->artisan('shop:variants-rollup')->assertSuccessful();
    expect($this->card->fresh()->stock_qty)->toBeNull();
});

it('ignores products without variants and trashed cards', function (): void {
    Product::factory()->create(['stock_qty' => 7]);
    $trashed = variantCard([], $this->size);
    $trashed->delete();
    DB::table('products')->where('id', $trashed->id)->update(['stock_qty' => 50]);

    $this->artisan('shop:variants-rollup')->expectsOutputToContain('Расхождений агрегатов исправлено: 0')->assertSuccessful();
});

it('stops at the time budget', function (): void {
    DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => 99]);

    expect(app(VariantAggregator::class)->rollupAll(microtime(true) - 1))->toBe([]);
    expect($this->card->fresh()->stock_qty)->toBe(99);
});

it('doctor reports SKU collisions between products and variants (VR-04, D-087)', function (): void {
    expect(doctorCheck('variant_skus')->status)->toBe(CheckStatus::Ok);

    $variant = ProductVariant::query()->where('product_id', $this->card->id)->first();
    DB::table('products')->where('id', $this->card->id)->update(['sku' => $variant->sku]);

    $check = doctorCheck('variant_skus');

    expect($check->status)->toBe(CheckStatus::Error)
        ->and($check->value)->toContain('1')
        ->and($check->hint)->toContain($variant->sku);
});
