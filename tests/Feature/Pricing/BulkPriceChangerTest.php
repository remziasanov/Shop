<?php

declare(strict_types=1);

use App\Enums\BulkPriceOperation;
use App\Enums\PriceChangeSource;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\BulkPriceChanger;

beforeEach(function (): void {
    $this->changer = app(BulkPriceChanger::class);
});

it('computes every operation in kopecks', function (BulkPriceOperation $operation, string $value, int $old, int $expected): void {
    expect($this->changer->newPrice($old, $operation, $value))->toBe($expected);
})->with([
    'increase percent' => [BulkPriceOperation::IncreasePercent, '10', 100_000, 110_000],
    'decrease percent' => [BulkPriceOperation::DecreasePercent, '10', 100_000, 90_000],
    'increase fixed' => [BulkPriceOperation::IncreaseFixed, '50,5', 100_000, 105_050],
    'decrease fixed' => [BulkPriceOperation::DecreaseFixed, '50.50', 100_000, 94_950],
    'percent with decimals' => [BulkPriceOperation::IncreasePercent, '12,5', 100_000, 112_500],
    'half a kopeck rounds up' => [BulkPriceOperation::IncreasePercent, '0.5', 10_010, 10_060],
    'rounds to kopecks' => [BulkPriceOperation::DecreasePercent, '33.3333', 100, 67],
    'floor at zero for percent' => [BulkPriceOperation::DecreasePercent, '150', 100_000, 0],
    'floor at zero for rubles' => [BulkPriceOperation::DecreaseFixed, '5000', 100_000, 0],
]);

it('rejects non-positive and malformed values', function (string $value): void {
    expect(fn () => $this->changer->newPrice(100, BulkPriceOperation::IncreasePercent, $value))
        ->toThrow(InvalidArgumentException::class);
})->with(['0', '-5', 'abc', '', '1e3']);

it('previews the first 20 products without changing them', function (): void {
    $products = Product::factory()->count(25)->create(['base_price' => '1000.00']);

    $rows = $this->changer->preview($products->pluck('id')->all(), BulkPriceOperation::IncreasePercent, '10');

    expect($rows)->toHaveCount(20)
        ->and($rows[0])->toMatchArray(['id' => $products[0]->id, 'before' => 100_000, 'after' => 110_000])
        ->and(Product::query()->where('base_price', '1000.00')->count())->toBe(25);
});

it('applies the change, records bulk history with the user and recalculates final prices', function (): void {
    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    PriceRule::factory()->discountPercent(10)->create();
    $a = Product::factory()->create(['base_price' => '1000.00']);
    $b = Product::factory()->create(['base_price' => '200.10']);
    $untouched = Product::factory()->create(['base_price' => '300.00']);

    $changed = $this->changer->apply([$a->id, $b->id, $a->id], BulkPriceOperation::IncreasePercent, '10');

    expect($changed)->toBe(2)
        ->and($a->fresh()->base_price)->toBe('1100.00')
        ->and($a->fresh()->final_price)->toBe('990.00')
        ->and($b->fresh()->base_price)->toBe('220.11')
        ->and($untouched->fresh()->base_price)->toBe('300.00');

    $rows = PriceHistory::query()->where('product_id', $a->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->field)->toBe('base_price')
        ->and($rows[0]->old_value)->toBe('1000.00')
        ->and($rows[0]->new_value)->toBe('1100.00')
        ->and($rows[0]->source)->toBe(PriceChangeSource::Bulk)
        ->and($rows[0]->user_id)->toBe($user->id)
        ->and(PriceHistory::query()->count())->toBe(2);
});

it('never goes below zero and skips products whose price does not change', function (): void {
    $cheap = Product::factory()->create(['base_price' => '100.00']);
    $free = Product::factory()->create(['base_price' => '0.00']);

    $changed = $this->changer->apply([$cheap->id, $free->id], BulkPriceOperation::DecreaseFixed, '500');

    expect($changed)->toBe(1)
        ->and($cheap->fresh()->base_price)->toBe('0.00')
        ->and($cheap->fresh()->final_price)->toBe('0.00')
        ->and(PriceHistory::query()->count())->toBe(1);
});

it('restores the manual change context afterwards', function (): void {
    $product = Product::factory()->create(['base_price' => '100.00']);

    $this->changer->apply([$product->id], BulkPriceOperation::IncreaseFixed, '1');
    $product->refresh()->update(['base_price' => '500.00']);

    expect(PriceHistory::query()->orderBy('id')->pluck('source')->all())
        ->toBe([PriceChangeSource::Bulk, PriceChangeSource::Manual]);
});

it('processes more products than one chunk', function (): void {
    $products = Product::factory()->count(BulkPriceChanger::CHUNK_SIZE + 5)->create(['base_price' => '10.00']);

    expect($this->changer->apply($products->pluck('id')->all(), BulkPriceOperation::IncreaseFixed, '1'))->toBe($products->count())
        ->and(Product::query()->where('base_price', '11.00')->count())->toBe($products->count());
});
