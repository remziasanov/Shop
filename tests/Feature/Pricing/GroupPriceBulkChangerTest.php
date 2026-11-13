<?php

declare(strict_types=1);

use App\Enums\GroupPriceBulkOperation;
use App\Enums\PriceChangeSource;
use App\Jobs\ApplyGroupPriceBulkChange;
use App\Models\CustomerGroup;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\GroupPriceBulkChanger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->bulk = app(GroupPriceBulkChanger::class);
    $this->group = CustomerGroup::factory()->create();
});

function exactPrice(Product|ProductVariant $item, CustomerGroup $group): ?string
{
    $row = ProductPriceTier::query()
        ->where('customer_group_id', $group->id)
        ->where('min_qty', 1)
        ->where('product_id', $item instanceof ProductVariant ? $item->product_id : $item->id)
        ->where('variant_key', $item instanceof ProductVariant ? $item->id : 0)
        ->first();

    return $row === null ? null : (string) $row->price;
}

describe('preview (GP-18)', function (): void {
    it('shows the first 20 items with the current and the new exact price', function (): void {
        $products = collect(range(1, 25))->map(fn () => gpProduct('1000.00'));
        gpTier($products[0], $this->group, 1, '999.00');

        $preview = $this->bulk->preview($products->pluck('id'), $this->group, GroupPriceBulkOperation::RetailMinusPercent, '15');

        expect($preview['count'])->toBe(25)
            ->and($preview['rows'])->toHaveCount(20)
            ->and($preview['rows'][0])->toMatchArray(['product_id' => $products[0]->id, 'before' => 99_900, 'after' => 85_000, 'skipped' => null])
            ->and($preview['rows'][1])->toMatchArray(['before' => null, 'after' => 85_000]);
    });

    it('writes nothing', function (): void {
        $product = gpProduct();

        $this->bulk->preview([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10');

        expect(ProductPriceTier::query()->count())->toBe(0)->and(PriceHistory::query()->count())->toBe(0);
    });

    it('flags items without a cost price for the cost operation', function (): void {
        $with = gpProduct('1000.00', ['cost_price' => '600.00']);
        $without = gpProduct('1000.00');

        $preview = $this->bulk->preview([$with->id, $without->id], $this->group, GroupPriceBulkOperation::CostPlusPercent, '25');

        expect($preview['rows'][0])->toMatchArray(['after' => 75_000, 'skipped' => null])
            ->and($preview['rows'][1])->toMatchArray(['skipped' => 'no_cost']);
    });

    it('rejects a missing or impossible value', function (mixed $value): void {
        expect(fn () => $this->bulk->preview([], $this->group, GroupPriceBulkOperation::RetailMinusPercent, $value))->toThrow(InvalidArgumentException::class);
    })->with([null, '', '0', '-5', 'abc', '100', '150']);
});

describe('apply', function (): void {
    it('sets the exact price to retail minus X percent, half up, without rounding (GP-21)', function (): void {
        $product = gpProduct('999.99');

        $result = $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '12,5');

        // 99 999 kopecks * 87.5 % = 87 499.125 -> 87 499.
        expect($result->changed)->toBe(1)->and(exactPrice($product, $this->group))->toBe('874.99');
    });

    it('sets the exact price to cost plus X percent', function (): void {
        $product = gpProduct('1000.00', ['cost_price' => '333.33']);

        $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::CostPlusPercent, '30');

        // 33 333 * 1.3 = 43 332.9 -> 43 333.
        expect(exactPrice($product, $this->group))->toBe('433.33');
    });

    it('replaces an existing exact price and keeps tiers', function (): void {
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '950.00');
        gpTier($product, $this->group, 10, '700.00');

        $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '20');

        expect(exactPrice($product, $this->group))->toBe('800.00')
            ->and(ProductPriceTier::query()->where('min_qty', 10)->count())->toBe(1);
    });

    it('deletes exact prices of the group only, not tiers or other groups', function (): void {
        $other = CustomerGroup::factory()->create();
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '950.00');
        gpTier($product, $this->group, 10, '700.00');
        gpTier($product, $other, 1, '900.00');

        $result = $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::Delete);

        expect($result->changed)->toBe(1)
            ->and(exactPrice($product, $this->group))->toBeNull()
            ->and(exactPrice($product, $other))->toBe('900.00')
            ->and(ProductPriceTier::query()->where('customer_group_id', $this->group->id)->count())->toBe(1);
    });

    it('writes group_price history rows with source bulk and the user', function (): void {
        $user = User::factory()->admin()->create();
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '950.00');

        $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10', $user->id);

        $row = PriceHistory::query()->first();

        expect($row->field)->toBe('group_price')
            ->and($row->min_qty)->toBe(1)
            ->and($row->customer_group_id)->toBe($this->group->id)
            ->and($row->old_value)->toBe('950.00')
            ->and($row->new_value)->toBe('900.00')
            ->and($row->source)->toBe(PriceChangeSource::Bulk)
            ->and($row->user_id)->toBe($user->id);
    });

    it('recomputes the denormalized group prices', function (): void {
        $product = gpProduct('1000.00');

        $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10');
        expect(gpRow($product, $this->group)['final'])->toBe('900.00');

        $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::Delete);
        expect(gpRow($product, $this->group))->toBeNull();
    });

    it('is idempotent: a second run changes nothing and writes no history', function (): void {
        $product = gpProduct('1000.00');
        $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10');

        $result = $this->bulk->apply([$product->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10');

        expect($result->changed)->toBe(0)->and($result->unchanged)->toBe(1)->and(PriceHistory::query()->count())->toBe(1);
    });

    it('skips items without a cost price and items that would end below a tier', function (): void {
        $noCost = gpProduct('1000.00');
        $conflict = gpProduct('1000.00', ['cost_price' => '500.00']);
        $fine = gpProduct('1000.00', ['cost_price' => '500.00']);
        gpTier($conflict, $this->group, 10, '700.00');

        $result = $this->bulk->apply([$noCost->id, $conflict->id, $fine->id], $this->group, GroupPriceBulkOperation::CostPlusPercent, '10');

        // 550 for the conflicting one is below its 700 tier? No: 550 < 700 means the tier costs more than the exact price.
        expect($result->changed)->toBe(1)
            ->and($result->skipped)->toBe(['no_cost' => 1, 'tier_conflict' => 1])
            ->and(exactPrice($fine, $this->group))->toBe('550.00')
            ->and(exactPrice($conflict, $this->group))->toBeNull();
    });

    it('works variant by variant for a card with variants', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard([], $size);
        $s = variantOf($card, [$size->values[0]], ['base_price' => '500.00']);
        $m = variantOf($card, [$size->values[1]], ['base_price' => '700.00', 'cost_price' => '100.00']);

        $this->bulk->apply([$card->id], $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10');

        expect(exactPrice($s, $this->group))->toBe('450.00')
            ->and(exactPrice($m, $this->group))->toBe('630.00')
            ->and(exactPrice($card, $this->group))->toBeNull()
            ->and(gpRow($card, $this->group)['final'])->toBe('450.00');
    });

    it('works in chunks of 200 products', function (): void {
        $ids = collect(range(1, 205))->map(fn () => gpProduct()->id);

        $result = $this->bulk->apply($ids, $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10');

        expect(GroupPriceBulkChanger::CHUNK_SIZE)->toBe(200)
            ->and($result->changed)->toBe(205)
            ->and(ProductPriceTier::query()->count())->toBe(205);
    });
});

describe('dispatch', function (): void {
    it('queues chunks and needs prices.bulk', function (): void {
        Queue::fake();
        $ids = collect(range(1, 3))->map(fn () => gpProduct()->id);

        expect(fn () => $this->bulk->dispatch($ids, $this->group, GroupPriceBulkOperation::Delete, null, staffWith(['products.view'])))
            ->toThrow(AuthorizationException::class);

        $result = $this->bulk->dispatch($ids, $this->group, GroupPriceBulkOperation::RetailMinusPercent, '10', staffWith(['prices.bulk']));

        expect($result)->toBe(['products' => 3, 'jobs' => 1]);
        Queue::assertPushed(ApplyGroupPriceBulkChange::class, 1);
    });

    it('applies through the job', function (): void {
        $product = gpProduct('1000.00');

        dispatch_sync(new ApplyGroupPriceBulkChange([$product->id], $this->group->id, 'retail_minus_percent', '10', null));

        expect(exactPrice($product, $this->group))->toBe('900.00');
    });
});
