<?php

declare(strict_types=1);

use App\Enums\PriceSource;
use App\Models\CustomerGroup;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductGroupPrice;
use App\Services\Pricing\GroupPriceRecalculator;
use App\Services\Pricing\NextTier;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\TierHint;
use App\Settings\PricingSettings;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->resolver = app(PriceResolver::class);
    $this->group = CustomerGroup::factory()->discount(10)->create();
});

it('returns the retail price when the group has nothing (retail source)', function (): void {
    $product = gpProduct('1000.00', ['compare_at_price' => '1200.00']);
    $other = CustomerGroup::factory()->create();

    $price = $this->resolver->resolve($product->fresh(), $other, 5);

    expect($price->unitPrice)->toBe(100_000)
        ->and($price->source)->toBe(PriceSource::Retail)
        ->and($price->tierMinQty)->toBeNull()
        ->and($price->oldPrice)->toBe(120_000)
        ->and($price->retailPrice)->toBe(100_000)
        ->and($price->nextTier)->toBeNull();
});

it('takes the discounted price of the group row (group_discount source)', function (): void {
    $product = gpProduct();

    $price = $this->resolver->resolve($product->fresh(), $this->group, 1);

    expect($price->unitPrice)->toBe(90_000)
        ->and($price->source)->toBe(PriceSource::GroupDiscount)
        ->and($price->retailPrice)->toBe(100_000);
});

it('takes the old price of the group row for the group_discount source', function (): void {
    $product = gpProduct();
    PriceRule::factory()->discountPercent(5)->showAsDiscount()->create()->groups()->attach($this->group->id);
    app(GroupPriceRecalculator::class)->recalculate([$product->id]);

    $price = $this->resolver->resolve($product->fresh(), $this->group, 1);

    // 1 000 - 5 % = 950, then the group discount: 855; the old price is the one before the rule.
    expect($price->unitPrice)->toBe(85_500)->and($price->oldPrice)->toBe(100_000);
});

it('prefers the exact price of the group to its discount (group_price source)', function (): void {
    $product = gpProduct();
    gpTier($product, $this->group, 1, '850.00');
    app(GroupPriceRecalculator::class)->recalculate([$product->id]);

    $price = $this->resolver->resolve($product->fresh(), $this->group, 3);

    expect($price->unitPrice)->toBe(85_000)
        ->and($price->source)->toBe(PriceSource::GroupPrice)
        ->and($price->tierMinQty)->toBeNull()
        ->and($price->oldPrice)->toBeNull();
});

it('resolves the tier boundaries: min_qty - 1, min_qty and more', function (): void {
    $product = gpProduct();
    gpTier($product, $this->group, 10, '800.00');
    gpTier($product, $this->group, 50, '700.00');

    $at = fn (int $qty) => $this->resolver->resolve($product->fresh(), $this->group, $qty);

    expect($at(9)->source)->toBe(PriceSource::GroupDiscount)
        ->and($at(9)->unitPrice)->toBe(90_000)
        ->and($at(10)->source)->toBe(PriceSource::Tier)
        ->and($at(10)->unitPrice)->toBe(80_000)
        ->and($at(10)->tierMinQty)->toBe(10)
        ->and($at(49)->unitPrice)->toBe(80_000)
        ->and($at(50)->unitPrice)->toBe(70_000)
        ->and($at(50)->tierMinQty)->toBe(50)
        ->and($at(1000)->unitPrice)->toBe(70_000);
});

it('picks the tier of the largest min_qty not above the quantity regardless of insertion order', function (): void {
    $product = gpProduct();
    gpTier($product, $this->group, 50, '700.00');
    gpTier($product, $this->group, 10, '800.00');
    gpTier($product, $this->group, 25, '750.00');

    expect($this->resolver->resolve($product->fresh(), $this->group, 30)->tierMinQty)->toBe(25);
});

it('reports the next tier', function (): void {
    $product = gpProduct();
    gpTier($product, $this->group, 10, '800.00');
    gpTier($product, $this->group, 50, '700.00');

    $below = $this->resolver->resolve($product->fresh(), $this->group, 4);
    $middle = $this->resolver->resolve($product->fresh(), $this->group, 10);
    $last = $this->resolver->resolve($product->fresh(), $this->group, 60);

    expect($below->nextTier)->toEqual(new NextTier(10, 80_000))
        ->and($middle->nextTier)->toEqual(new NextTier(50, 70_000))
        ->and($last->nextTier)->toBeNull();
});

it('reports the next tier for an exact price too', function (): void {
    $product = gpProduct();
    gpTier($product, $this->group, 1, '850.00');
    gpTier($product, $this->group, 10, '800.00');

    expect($this->resolver->resolve($product->fresh(), $this->group, 2)->nextTier)->toEqual(new NextTier(10, 80_000));
});

it('treats a tier that costs more than the retail price like any other (first match wins)', function (): void {
    $product = gpProduct();
    gpTier($product, $this->group, 5, '1200.00');

    expect($this->resolver->resolve($product->fresh(), $this->group, 5)->unitPrice)->toBe(120_000);
});

it('keeps the tiers of different groups apart', function (): void {
    $product = gpProduct();
    $second = CustomerGroup::factory()->create();
    gpTier($product, $this->group, 10, '800.00');
    gpTier($product, $second, 10, '600.00');

    expect($this->resolver->resolve($product->fresh(), $this->group, 10)->unitPrice)->toBe(80_000)
        ->and($this->resolver->resolve($product->fresh(), $second, 10)->unitPrice)->toBe(60_000)
        ->and($this->resolver->resolve($product->fresh(), CustomerGroup::guest(), 10)->unitPrice)->toBe(100_000);
});

it('resolves the guest and retail groups by the same rules', function (): void {
    $product = gpProduct();
    $retail = CustomerGroup::retail();
    gpTier($product, $retail, 3, '900.00');

    expect($this->resolver->resolve($product->fresh(), $retail, 3)->source)->toBe(PriceSource::Tier)
        ->and($this->resolver->resolve($product->fresh(), CustomerGroup::guest(), 3)->source)->toBe(PriceSource::Retail);
});

it('resolves variants by their own tiers, exact prices and retail prices', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    $s = variantOf($card, [$size->values[0]], ['base_price' => '500.00']);
    $m = variantOf($card, [$size->values[1]], ['base_price' => '700.00']);
    gpTier($s, $this->group, 10, '400.00');
    gpTier($m, $this->group, 1, '600.00');

    $resolve = fn ($item, int $qty) => $this->resolver->resolve($item->fresh(), $this->group, $qty);

    expect($resolve($s, 1)->unitPrice)->toBe(45_000)
        ->and($resolve($s, 1)->source)->toBe(PriceSource::GroupDiscount)
        ->and($resolve($s, 10)->unitPrice)->toBe(40_000)
        ->and($resolve($m, 1)->source)->toBe(PriceSource::GroupPrice)
        ->and($resolve($m, 10)->unitPrice)->toBe(60_000)
        ->and($resolve($m, 10)->retailPrice)->toBe(70_000);
});

it('resolves a card with variants by its card-level row (listing price)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    variantOf($card, [$size->values[0]], ['base_price' => '500.00']);
    variantOf($card, [$size->values[1]], ['base_price' => '700.00']);

    $price = $this->resolver->resolve($card->fresh(), $this->group, 1);

    expect($price->unitPrice)->toBe(45_000)->and($price->source)->toBe(PriceSource::GroupDiscount);
});

it('loads the data of many items in two queries per 500 products', function (): void {
    $products = collect(range(1, 12))->map(fn () => gpProduct())->each(fn ($product) => gpTier($product, $this->group, 10, '800.00'));
    $items = $products->map->fresh();

    DB::enableQueryLog();
    $book = $this->resolver->preload($items, $this->group);

    foreach ($items as $product) {
        $this->resolver->resolve($product, $this->group, 10, $book);
        $this->resolver->resolve($product, $this->group, 1, $book);
    }

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2);
});

it('loads on demand when no or a foreign price book is given', function (): void {
    $product = gpProduct();
    $other = CustomerGroup::factory()->create();
    gpTier($product, $this->group, 10, '800.00');
    $bookOfOther = $this->resolver->preload([$product->fresh()], $other);

    expect($this->resolver->resolve($product->fresh(), $this->group, 10, $bookOfOther)->unitPrice)->toBe(80_000);
});

it('offers the retail price to strike through only for groups that show it', function (): void {
    $product = gpProduct();
    $showing = CustomerGroup::factory()->discount(10)->showRetailPrice()->create();

    $price = $this->resolver->resolve($product->fresh(), $showing, 1);

    expect($price->retailStrikePrice($showing))->toBe(100_000)
        ->and($price->retailStrikePrice($this->group))->toBeNull()
        ->and($this->resolver->resolve($product->fresh(), CustomerGroup::retail(), 1)->retailStrikePrice($showing))->toBeNull();
});

function gpHint(Product $product, CustomerGroup $group, int $qty): ?TierHint
{
    $resolver = app(PriceResolver::class);

    return $resolver->tierHint($resolver->resolve($product->fresh(), $group, $qty), $qty);
}

describe('next tier hint (GP-11, GP-19)', function (): void {
    beforeEach(function (): void {
        $this->product = gpProduct();
        gpTier($this->product, $this->group, 10, '800.00');
        gpTier($this->product, $this->group, 100, '700.00');
    });

    it('hints when the missing quantity is within the percent of the current quantity', function (): void {
        $result = gpHint($this->product, $this->group, 8);

        expect($result->missingQty)->toBe(2)->and($result->minQty)->toBe(10)->and($result->price)->toBe(80_000);
    });

    it('hints when the missing quantity is within the piece limit', function (): void {
        // 100 - 85 = 15 pieces: more than 50 % of 85 is not needed, 15 pieces are within the 20 piece limit.
        expect(gpHint($this->product, $this->group, 85)?->missingQty)->toBe(15);
    });

    it('does not hint when the next tier is too far or there is none', function (): void {
        // 9 pieces missing: within 20 pieces.
        expect(gpHint($this->product, $this->group, 1))->not->toBeNull()
            ->and(gpHint($this->product, $this->group, 150))->toBeNull();

        $settings = app(PricingSettings::class);
        $settings->tier_hint_qty = 5;
        $settings->save();

        // 9 missing > 5 pieces and > 50 % of 1.
        expect(gpHint($this->product, $this->group, 1))->toBeNull()
            // 2 missing <= 5 pieces.
            ->and(gpHint($this->product, $this->group, 8))->not->toBeNull();
    });

    it('does not hint when the option is off', function (): void {
        $settings = app(PricingSettings::class);
        $settings->tier_hint_enabled = false;
        $settings->save();

        expect(gpHint($this->product, $this->group, 8))->toBeNull();
    });
});

it('stores group rows only when the price differs from retail', function (): void {
    $plain = gpProduct();
    $free = CustomerGroup::factory()->create();

    expect(ProductGroupPrice::query()->where('product_id', $plain->id)->where('customer_group_id', $this->group->id)->count())->toBe(1)
        ->and(ProductGroupPrice::query()->where('customer_group_id', $free->id)->count())->toBe(0);
});
