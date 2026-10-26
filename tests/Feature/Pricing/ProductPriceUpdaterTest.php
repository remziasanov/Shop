<?php

declare(strict_types=1);

use App\Enums\ProductFlag;
use App\Enums\RoundingDirection;
use App\Enums\RoundingStep;
use App\Models\Category;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Services\Pricing\PriceInput;
use App\Services\Pricing\PriceRuleRepository;
use App\Services\Pricing\ProductPriceUpdater;
use App\Settings\PricingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->updater = app(ProductPriceUpdater::class);
});

describe('calculate() preview', function (): void {
    it('uses the base price when no fixed price is set', function (): void {
        expect($this->updater->calculate('1290.50', null, null))->toBe(['final_price' => '1290.50', 'display_old_price' => null]);
    });

    it('prefers the fixed price over the base price', function (): void {
        expect($this->updater->calculate('1000.00', '850.00', null))->toBe(['final_price' => '850.00', 'display_old_price' => null]);
    });

    it('shows compare_at_price as the old price only when greater than the final price', function (): void {
        expect($this->updater->calculate('1000.00', null, '1500.00'))->toBe(['final_price' => '1000.00', 'display_old_price' => '1500.00'])
            ->and($this->updater->calculate('1000.00', null, '1000.00')['display_old_price'])->toBeNull()
            ->and($this->updater->calculate('1000.00', null, '900.00')['display_old_price'])->toBeNull()
            ->and($this->updater->calculate('1000.00', '700.00', '900.00')['display_old_price'])->toBe('900.00');
    });

    it('does not suffer from float errors', function (): void {
        expect($this->updater->calculate('0.10', null, '0.30')['display_old_price'])->toBe('0.30')
            ->and($this->updater->calculate('19999999999.99', null, null)['final_price'])->toBe('19999999999.99');
    });

    it('takes rules matching the category, brand, flags and cost into account', function (): void {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        PriceRule::factory()->forCategories([$root])->markupPercent(10)->create();
        PriceRule::factory()->forBrands(['Аврора'])->markupFixed(50)->create();
        PriceRule::factory()->forFlags([ProductFlag::New])->discountFixed(20)->create();
        PriceRule::factory()->costMarkupPercent(100)->priority(99)->create();

        expect($this->updater->calculate('1000.00', null, null, $child->id, 'аврора', false, true, '400.00'))
            ->toBe(['final_price' => '910.00', 'display_old_price' => null])
            ->and($this->updater->calculate('1000.00', null, null))->toBe(['final_price' => '1000.00', 'display_old_price' => null]);
    });

    it('explains a prepared input', function (): void {
        PriceRule::factory()->markupPercent(20)->create(['name' => 'Электроника']);

        $result = $this->updater->explain(new PriceInput(null, null, null, false, false, 100_000));

        expect($result->finalPrice)->toBe(120_000)
            ->and($result->explanation->toLines())->toHaveCount(2);
    });
});

describe('saving a product', function (): void {
    it('applies active rules', function (): void {
        PriceRule::factory()->markupPercent(20)->create();
        PriceRule::factory()->discountPercent(10)->showAsDiscount()->create();

        $product = Product::factory()->create(['base_price' => '1000.00']);

        expect($product->final_price)->toBe('1080.00')
            ->and($product->display_old_price)->toBe('1200.00')
            ->and($product->price_calculated_at)->not->toBeNull();
    });

    it('ignores inactive and expired rules', function (): void {
        PriceRule::factory()->markupPercent(20)->inactive()->create();
        PriceRule::factory()->markupPercent(30)->between(null, now()->subMinute())->create();
        PriceRule::factory()->markupPercent(40)->between(now()->addDay(), null)->create();

        expect(Product::factory()->create(['base_price' => '1000.00'])->final_price)->toBe('1000.00');
    });

    it('matches a rule on a parent category for a product in a subcategory', function (): void {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $grandchild = Category::factory()->childOf($child)->create();
        PriceRule::factory()->forCategories([$root])->markupFixed(100)->create();

        $deep = Product::factory()->inCategory($grandchild)->create(['base_price' => '1000.00']);
        $other = Product::factory()->create(['base_price' => '1000.00']);

        expect($deep->final_price)->toBe('1100.00')
            ->and($other->final_price)->toBe('1000.00');

        PriceRule::query()->update(['include_subcategories' => false]);
        app(PriceRuleRepository::class)->flush();

        expect(Product::factory()->inCategory($grandchild)->create(['base_price' => '1000.00'])->final_price)->toBe('1000.00');
    });

    it('recalculates when rule matching inputs change', function (): void {
        PriceRule::factory()->forBrands(['Особый'])->markupFixed(100)->create();
        PriceRule::factory()->forFlags([ProductFlag::Featured])->markupFixed(10)->create();
        PriceRule::factory()->costMarkupPercent(50)->create();

        $product = Product::factory()->create(['base_price' => '1000.00', 'brand' => 'Другой']);
        expect($product->final_price)->toBe('1000.00');

        $product->update(['brand' => 'особый']);
        expect($product->fresh()->final_price)->toBe('1100.00');

        $product->update(['is_featured' => true]);
        expect($product->fresh()->final_price)->toBe('1110.00');

        $product->update(['cost_price' => '800.00']);
        // Same priority, so by id: brand (+100), featured (+10), then the cost markup replaces the price: 800 * 1.5.
        expect($product->fresh()->final_price)->toBe('1200.00');

        $other = Category::factory()->create();
        PriceRule::factory()->forCategories([$other])->discountFixed(200)->create();
        $product->update(['category_id' => $other->id]);
        expect($product->fresh()->final_price)->toBe('1000.00');
    });

    it('rounds using the pricing settings', function (): void {
        $settings = app(PricingSettings::class);
        $settings->rounding_step = RoundingStep::Ten;
        $settings->rounding_direction = RoundingDirection::Up;
        $settings->psychological_price = true;
        $settings->save();

        PriceRule::factory()->markupPercent(1)->create();

        // 1000 * 1.01 = 1010, rounded to 1010, minus 1 RUB.
        expect(Product::factory()->create(['base_price' => '1000.00'])->final_price)->toBe('1009.00');
    });
});

describe('recalculate()', function (): void {
    it('writes only changed rows, keeps updated_at and creates no price history', function (): void {
        $products = Product::factory()->count(3)->create(['base_price' => '1000.00']);
        $untouched = $products[2];
        DB::table('products')->update(['updated_at' => '2020-01-01 00:00:00']);

        PriceRule::factory()->forProducts([$products[0], $products[1]])->markupPercent(10)->create();

        $changed = $this->updater->recalculate($products->pluck('id'));

        expect($changed)->toBe(2)
            ->and($products[0]->fresh()->final_price)->toBe('1100.00')
            ->and($products[1]->fresh()->final_price)->toBe('1100.00')
            ->and($untouched->fresh()->final_price)->toBe('1000.00')
            ->and(DB::table('products')->where('updated_at', '!=', '2020-01-01 00:00:00')->count())->toBe(0)
            ->and(PriceHistory::query()->count())->toBe(0)
            ->and($this->updater->recalculate($products->pluck('id')))->toBe(0);
    });

    it('updates the old price and the calculation time', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:30'));
        $product = Product::factory()->create(['base_price' => '1000.00']);
        DB::table('products')->update(['price_calculated_at' => '2020-01-01 00:00:00']);
        PriceRule::factory()->discountPercent(10)->showAsDiscount()->create();

        $this->updater->recalculate([$product->id, $product->id]);

        $fresh = $product->fresh();
        expect($fresh->final_price)->toBe('900.00')
            ->and($fresh->display_old_price)->toBe('1000.00')
            ->and($fresh->price_calculated_at->toDateTimeString())->toBe('2026-12-31 23:59:30');
    });

    it('processes more than one chunk', function (): void {
        $products = Product::factory()->count(3)->create(['base_price' => '100.00']);
        PriceRule::factory()->markupFixed(1)->create();

        $ids = $products->pluck('id')->all();

        expect($this->updater->recalculate(new ArrayIterator($ids)))->toBe(3);
    });

    it('includes soft-deleted products', function (): void {
        $product = Product::factory()->create(['base_price' => '100.00']);
        $product->delete();
        PriceRule::factory()->markupFixed(1)->create();

        expect($this->updater->recalculate([$product->id]))->toBe(1)
            ->and(Product::withTrashed()->find($product->id)->final_price)->toBe('101.00');
    });
});
