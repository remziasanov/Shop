<?php

declare(strict_types=1);

use App\Enums\PriceChangeSource;
use App\Jobs\RecalculatePrices;
use App\Models\Category;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\PriceChangeContext;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Pricing\PriceRuleRepository;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Variants\VariantAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->size = optionWith('Размер', ['S', 'M', 'L']);
    $this->card = variantCard(['base_price' => '1000.00'], $this->size);
});

function priceOfVariant(ProductVariant $variant): string
{
    return $variant->fresh()->final_price;
}

it('inherits the card price and uses an own one (VR-07)', function (): void {
    $inherit = variantOf($this->card, [$this->size->values[0]]);
    $own = variantOf($this->card, [$this->size->values[1]], ['base_price' => '1500.00']);

    expect($inherit->final_price)->toBe('1000.00')
        ->and($own->final_price)->toBe('1500.00')
        ->and($inherit->price_calculated_at)->not->toBeNull();
});

it('sets the card price to the minimum and final_price_max to the maximum of the active variants (VR-07)', function (): void {
    variantOf($this->card, [$this->size->values[0]], ['base_price' => '1200.00']);
    variantOf($this->card, [$this->size->values[1]], ['base_price' => '900.00']);
    $l = variantOf($this->card, [$this->size->values[2]], ['base_price' => '2000.00']);

    $card = $this->card->fresh();
    expect($card->final_price)->toBe('900.00')->and($card->final_price_max)->toBe('2000.00');

    // Inactive variants do not count.
    $l->update(['is_active' => false]);
    expect($this->card->fresh()->final_price_max)->toBe('1200.00');

    $l->update(['is_active' => true]);
    ProductVariant::query()->where('product_id', $this->card->id)->where('base_price', '900.00')->first()->delete();
    expect($this->card->fresh()->final_price)->toBe('1200.00');
});

it('takes the old price of the cheapest variant (19.5)', function (): void {
    variantOf($this->card, [$this->size->values[0]], ['base_price' => '1200.00', 'compare_at_price' => '1500.00']);
    variantOf($this->card, [$this->size->values[1]], ['base_price' => '900.00', 'compare_at_price' => '1100.00']);

    $card = $this->card->fresh();
    expect($card->final_price)->toBe('900.00')->and($card->display_old_price)->toBe('1100.00');

    $this->card->update(['compare_at_price' => '2000.00']);
    $third = variantOf($this->card, [$this->size->values[2]], ['base_price' => '800.00']);

    // The cheapest variant has no own old price: it inherits the card's.
    expect($third->display_old_price)->toBe('2000.00')->and($this->card->fresh()->display_old_price)->toBe('2000.00');
});

it('a fixed price of a variant disables rules for it (VR-23)', function (): void {
    PriceRule::factory()->discountPercent(10)->create();
    app(PriceRuleRepository::class)->flush();

    $plain = variantOf($this->card, [$this->size->values[0]]);
    $fixed = variantOf($this->card, [$this->size->values[1]], ['fixed_price' => '950.00']);

    expect($plain->final_price)->toBe('900.00')
        ->and($fixed->final_price)->toBe('950.00')
        ->and($this->card->fresh()->final_price)->toBe('900.00')
        ->and($this->card->fresh()->final_price_max)->toBe('950.00');
});

it('rule scope comes from the card: category, brand, flags and the product itself (VR-23)', function (): void {
    $other = Category::factory()->create();
    $inside = Category::query()->findOrFail($this->card->category_id);
    PriceRule::factory()->discountPercent(10)->forCategories([$inside], false)->create();
    app(PriceRuleRepository::class)->flush();

    $variant = variantOf($this->card, [$this->size->values[0]]);
    expect($variant->final_price)->toBe('900.00');

    $elsewhere = variantCard(['base_price' => '1000.00', 'category_id' => $other->id], $this->size);
    $v2 = variantOf($elsewhere, [$this->size->values[0]]);
    expect($v2->final_price)->toBe('1000.00');
});

it('recalculates the variants when the card prices or scope change (VR-07)', function (): void {
    $a = variantOf($this->card, [$this->size->values[0]]);
    $b = variantOf($this->card, [$this->size->values[1]], ['base_price' => '1500.00']);

    $this->card->update(['base_price' => '1100.00']);

    expect(priceOfVariant($a))->toBe('1100.00')
        ->and(priceOfVariant($b))->toBe('1500.00')
        ->and($this->card->fresh()->final_price)->toBe('1100.00')
        ->and($this->card->fresh()->final_price_max)->toBe('1500.00');

    // The card's fixed price is inherited by variants without their own (19.5).
    $this->card->update(['fixed_price' => '700.00']);
    expect(priceOfVariant($a))->toBe('700.00');
});

it('does not overwrite the aggregates when a card with variants is saved for other reasons', function (): void {
    variantOf($this->card, [$this->size->values[0]], ['base_price' => '1200.00']);
    variantOf($this->card, [$this->size->values[1]], ['base_price' => '900.00']);

    $this->card->fresh()->update(['name' => 'Другое название']);

    expect($this->card->fresh()->final_price)->toBe('900.00')->and($this->card->fresh()->final_price_max)->toBe('1200.00');
});

it('records variant price changes with variant_id and the source (VR-22)', function (): void {
    $variant = variantOf($this->card, [$this->size->values[0]]);

    expect(PriceHistory::query()->count())->toBe(0);

    $variant->update(['base_price' => '1300.00']);
    app(PriceChangeContext::class)->run(PriceChangeSource::Bulk, null, fn () => $variant->update(['fixed_price' => '1250.00']));

    $rows = PriceHistory::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->variant_id)->toBe($variant->id)
        ->and($rows[0]->product_id)->toBe($this->card->id)
        ->and($rows[0]->field)->toBe('base_price')
        ->and($rows[0]->old_value)->toBeNull()
        ->and($rows[0]->new_value)->toBe('1300.00')
        ->and($rows[0]->source)->toBe(PriceChangeSource::Manual)
        ->and($rows[1]->field)->toBe('fixed_price')
        ->and($rows[1]->source)->toBe(PriceChangeSource::Bulk)
        ->and($rows[1]->load('variant')->variant->is($variant))->toBeTrue();

    // A card price change leaves variant_id empty.
    $this->card->update(['base_price' => '1111.00']);
    expect(PriceHistory::query()->latest('id')->first()->variant_id)->toBeNull();
});

it('does not record the final price recalculation of a variant', function (): void {
    variantOf($this->card, [$this->size->values[0]]);
    PriceRule::factory()->discountPercent(10)->create();
    app(PriceRuleRepository::class)->flush();

    app(ProductPriceUpdater::class)->recalculate([$this->card->id]);

    expect(PriceHistory::query()->count())->toBe(0);
});

describe('batch recalculation (PR-02)', function (): void {
    it('recalculates variants and card aggregates of products with and without variants', function (): void {
        $a = variantOf($this->card, [$this->size->values[0]]);
        $b = variantOf($this->card, [$this->size->values[1]], ['base_price' => '2000.00']);
        $plain = Product::factory()->create(['base_price' => '500.00']);

        PriceRule::factory()->discountPercent(10)->create();
        app(PriceRuleRepository::class)->flush();

        $changed = app(ProductPriceUpdater::class)->recalculate([$this->card->id, $plain->id]);

        expect($changed)->toBe(3)
            ->and(priceOfVariant($a))->toBe('900.00')
            ->and(priceOfVariant($b))->toBe('1800.00')
            ->and($this->card->fresh()->final_price)->toBe('900.00')
            ->and($this->card->fresh()->final_price_max)->toBe('1800.00')
            ->and($plain->fresh()->final_price)->toBe('450.00')
            ->and($plain->fresh()->final_price_max)->toBeNull();

        // Idempotent: nothing changes the second time.
        expect(app(ProductPriceUpdater::class)->recalculate([$this->card->id, $plain->id]))->toBe(0);
    });

    it('queues a rule change as jobs that cover the variants of the card scope', function (): void {
        $a = variantOf($this->card, [$this->size->values[0]]);
        $rule = PriceRule::factory()->discountPercent(20)->forProducts([$this->card])->create();

        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        expect(priceOfVariant($a))->toBe('800.00')->and($this->card->fresh()->final_price)->toBe('800.00');
    });

    it('splits cards into jobs by priced entities: 500 variants weigh as much as 500 products', function (): void {
        Queue::fake();
        $cards = [];

        foreach (range(1, 6) as $i) {
            $card = variantCard(['base_price' => '100.00'], $this->size);
            ProductVariant::factory()->count(100)->forProduct($card)->create();
            $cards[] = $card->id;
        }

        $plain = Product::factory()->count(3)->create()->pluck('id')->all();

        $result = app(PriceRuleRecalculator::class)->dispatch(array_merge($cards, $plain));

        // 6 cards x 100 variants = 600 entities + 3 products: a job holds up to 500 entities, never more than 500 ids.
        expect($result['products'])->toBe(9)->and($result['jobs'])->toBe(2);
        Queue::assertPushed(RecalculatePrices::class, 2);
    });

    it('processes variants in batches of 500 and rolls the aggregates up after each', function (): void {
        $cards = [];

        foreach (range(1, 6) as $i) {
            $card = variantCard(['base_price' => '100.00'], $this->size);
            DB::table('product_variants')->insert(array_map(fn (int $n): array => [
                'product_id' => $card->id, 'sku' => "B{$i}-{$n}", 'combo_hash' => sha1("{$i}-{$n}"), 'final_price' => '1.00',
                'stock_qty' => 1, 'reserved_qty' => 0, 'is_active' => true, 'sort_order' => $n, 'created_at' => now(), 'updated_at' => now(),
            ], range(1, 100)));
            $cards[] = $card->id;
        }

        PriceRule::factory()->discountPercent(10)->create();
        app(PriceRuleRepository::class)->flush();

        $changed = app(ProductPriceUpdater::class)->recalculate($cards);

        expect($changed)->toBe(600)
            ->and(DB::table('product_variants')->where('final_price', '90.00')->count())->toBe(600)
            ->and(DB::table('products')->whereIn('id', $cards)->where('final_price', '90.00')->where('final_price_max', '90.00')->count())->toBe(6);
    });

    it('the nightly command recalculates cards with variants (shop:recalc-prices --all --sync)', function (): void {
        $a = variantOf($this->card, [$this->size->values[0]]);
        PriceRule::factory()->discountPercent(50)->create();

        $this->artisan('shop:recalc-prices --all --sync')->assertSuccessful();

        expect(priceOfVariant($a))->toBe('500.00')->and($this->card->fresh()->final_price)->toBe('500.00');
    });
});

it('keeps stale aggregates visible to the rollup command and fixes them (19.5)', function (): void {
    variantOf($this->card, [$this->size->values[0]], ['base_price' => '700.00']);
    DB::table('products')->where('id', $this->card->id)->update(['final_price' => '1.00', 'final_price_max' => '2.00']);

    $found = app(VariantAggregator::class)->discrepancies();
    expect(collect($found)->pluck('field')->all())->toContain('final_price', 'final_price_max');

    $this->artisan('shop:variants-rollup')->assertSuccessful();

    expect($this->card->fresh()->final_price)->toBe('700.00')->and($this->card->fresh()->final_price_max)->toBe('700.00');
});
