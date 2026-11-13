<?php

declare(strict_types=1);

use App\Console\Commands\RecalcPricesCommand;
use App\Enums\PriceRuleAction;
use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Jobs\RecalculatePrices;
use App\Models\CustomerGroup;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductGroupPrice;
use App\Services\Catalog\CatalogVersion;
use App\Services\Pricing\GroupPriceRecalculator;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Pricing\PriceRuleSaver;
use App\Services\Pricing\RecalculationProgress;
use App\Settings\PricingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gpRuleState(array $overrides = []): array
{
    return array_merge([
        'name' => 'Правило',
        'is_active' => true,
        'action' => PriceRuleAction::DiscountPercent->value,
        'value' => '10',
        'scope' => 'all',
        'group_ids' => [],
    ], $overrides);
}

beforeEach(function (): void {
    $this->recalc = app(GroupPriceRecalculator::class);
    $this->group = CustomerGroup::factory()->create();
});

describe('rows of the group discount', function (): void {
    it('creates no rows while no group has anything to change', function (): void {
        $product = gpProduct();

        expect(ProductGroupPrice::query()->count())->toBe(0)
            ->and($this->recalc->recalculate([$product->id]))->toBe(0);
    });

    it('creates a row for a product when the group gets a discount (GP-06, GP-07)', function (): void {
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '15']);

        expect(gpRow($product, $this->group))->toBe(['final' => '850.00', 'old' => null]);
    });

    it('creates rows for products created after the discount exists', function (): void {
        $this->group->update(['default_discount_percent' => '10']);

        $product = gpProduct('2000.00');

        expect(gpRow($product, $this->group)['final'])->toBe('1800.00');
    });

    it('follows a changed base price of the product', function (): void {
        $this->group->update(['default_discount_percent' => '10']);
        $product = gpProduct('1000.00');

        $product->update(['base_price' => '2000.00']);

        expect(gpRow($product, $this->group)['final'])->toBe('1800.00');
    });

    it('deletes the row when the discount goes back to zero', function (): void {
        $product = gpProduct();
        $this->group->update(['default_discount_percent' => '10']);
        expect(gpRow($product, $this->group))->not->toBeNull();

        $this->group->update(['default_discount_percent' => '0']);

        expect(gpRow($product, $this->group))->toBeNull()
            ->and(ProductGroupPrice::query()->count())->toBe(0);
    });

    it('rewrites the rows when the discount changes', function (): void {
        $product = gpProduct();
        $this->group->update(['default_discount_percent' => '10']);
        $this->group->update(['default_discount_percent' => '20']);

        expect(gpRow($product, $this->group)['final'])->toBe('800.00')
            ->and(ProductGroupPrice::query()->count())->toBe(1);
    });

    it('keeps the groups apart', function (): void {
        $product = gpProduct();
        $second = CustomerGroup::factory()->discount(30)->create();
        $this->group->update(['default_discount_percent' => '10']);

        expect(gpRow($product, $this->group)['final'])->toBe('900.00')
            ->and(gpRow($product, $second)['final'])->toBe('700.00');
    });

    it('does not store the retail price: no row for a discount that rounds to nothing', function (): void {
        $product = gpProduct('0.01');
        $this->group->update(['default_discount_percent' => '10']);

        // 1 kopeck * 90 % = 0.9 kopeck, rounded half up to 1: the same as retail.
        expect(gpRow($product, $this->group))->toBeNull();
    });

    it('drops the rows of a deleted group by cascade and keeps the others', function (): void {
        $product = gpProduct();
        $second = CustomerGroup::factory()->discount(30)->create();
        $this->group->update(['default_discount_percent' => '10']);

        $this->group->delete();

        expect(ProductGroupPrice::query()->where('customer_group_id', $this->group->id)->count())->toBe(0)
            ->and(gpRow($product, $second))->not->toBeNull();
    });

    it('bumps the catalog version when rows change', function (): void {
        $product = gpProduct();
        $this->group->update(['default_discount_percent' => '10']);
        $before = CatalogVersion::current();

        $this->travel(1)->second();
        $product->update(['base_price' => '1500.00']);

        expect(CatalogVersion::current())->not->toBe($before);
    });
});

describe('exact prices', function (): void {
    it('creates a row for an exact price and ignores the discount for it', function (): void {
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '10']);
        gpTier($product, $this->group, 1, '950.00');

        $this->recalc->recalculate([$product->id]);

        expect(gpRow($product, $this->group))->toBe(['final' => '950.00', 'old' => null]);
    });

    it('creates no row for an exact price that equals the retail price', function (): void {
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '1000.00');

        $this->recalc->recalculate([$product->id]);

        expect(gpRow($product, $this->group))->toBeNull();
    });

    it('is not rounded (GP-21)', function (): void {
        $settings = app(PricingSettings::class);
        $settings->rounding_step = RoundingStep::Ten;
        $settings->rounding_direction = RoundingDirection::Up;
        $settings->save();
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '953.00');

        $this->recalc->recalculate([$product->id]);

        expect(gpRow($product, $this->group)['final'])->toBe('953.00');
    });

    it('is not affected by tiers', function (): void {
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 10, '500.00');

        $this->recalc->recalculate([$product->id]);

        expect(gpRow($product, $this->group))->toBeNull();
    });

    it('is recomputed for just the product and group (the tier service path)', function (): void {
        $product = gpProduct('1000.00');
        $other = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '10']);
        gpTier($product, $this->group, 1, '700.00');
        DB::table('product_group_prices_final')->where('product_id', $other->id)->update(['final_price' => '1.00']);

        $this->recalc->recalculate([$product->id], [$this->group->id]);

        expect(gpRow($product, $this->group)['final'])->toBe('700.00')
            ->and(gpRow($other, $this->group)['final'])->toBe('1.00');
    });
});

describe('price rules', function (): void {
    it('applies a rule bound to the group to its rows only and never to the retail price (GP-04)', function (): void {
        $product = gpProduct('1000.00');
        $second = CustomerGroup::factory()->create();

        app(PriceRuleSaver::class)->save(null, gpRuleState(['group_ids' => [$this->group->id]]));

        expect($product->fresh()->final_price)->toBe('1000.00')
            ->and(gpRow($product, $this->group)['final'])->toBe('900.00')
            ->and(gpRow($product, $second))->toBeNull();
    });

    it('applies a rule for everyone to the retail price and so to the groups', function (): void {
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '10']);

        app(PriceRuleSaver::class)->save(null, gpRuleState(['action' => PriceRuleAction::MarkupPercent->value, 'value' => '20']));

        expect($product->fresh()->final_price)->toBe('1200.00')
            ->and(gpRow($product, $this->group)['final'])->toBe('1080.00');
    });

    it('recalculates the group when a rule is bound to it later and when it is unbound', function (): void {
        $product = gpProduct('1000.00');
        $rule = app(PriceRuleSaver::class)->save(null, gpRuleState());
        expect($product->fresh()->final_price)->toBe('900.00');

        app(PriceRuleSaver::class)->save($rule, gpRuleState(['group_ids' => [$this->group->id]]));

        expect($product->fresh()->final_price)->toBe('1000.00')
            ->and(gpRow($product, $this->group)['final'])->toBe('900.00');

        app(PriceRuleSaver::class)->save($rule, gpRuleState());

        expect($product->fresh()->final_price)->toBe('900.00')
            ->and(gpRow($product, $this->group))->toBeNull();
    });

    it('queues only the groups of a group-bound rule without touching retail', function (): void {
        Queue::fake();
        gpProduct();

        app(PriceRuleSaver::class)->save(null, gpRuleState(['group_ids' => [$this->group->id]]));

        Queue::assertPushed(RecalculatePrices::class, fn (RecalculatePrices $job): bool => $job->groupIds === [$this->group->id]);
    });

    it('queues retail and all groups for a rule for everyone', function (): void {
        Queue::fake();
        gpProduct();

        app(PriceRuleSaver::class)->save(null, gpRuleState());

        Queue::assertPushed(RecalculatePrices::class, fn (RecalculatePrices $job): bool => $job->groupIds === null);
    });

    it('removes the group price when the rule is deleted or switched off', function (): void {
        $product = gpProduct('1000.00');
        $saver = app(PriceRuleSaver::class);
        $rule = $saver->save(null, gpRuleState(['group_ids' => [$this->group->id]]));
        expect(gpRow($product, $this->group))->not->toBeNull();

        $rule->update(['is_active' => false]);
        $saver->toggled($rule);
        expect(gpRow($product, $this->group))->toBeNull();

        $rule->update(['is_active' => true]);
        $saver->toggled($rule);
        expect(gpRow($product, $this->group))->not->toBeNull();

        $saver->delete($rule);
        expect(gpRow($product, $this->group))->toBeNull();
    });

    it('follows the period of a group rule (boundaries and nightly recalculation)', function (): void {
        $this->travelTo(now()->startOfMinute());
        @unlink(RecalcPricesCommand::lastRunPath());
        $product = gpProduct('1000.00');
        $rule = PriceRule::factory()->discountPercent(10)->create(['starts_at' => now()->addMinutes(30)]);
        $rule->groups()->attach($this->group->id);
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load(['targets', 'groups'])));
        expect(gpRow($product, $this->group))->toBeNull();

        $this->travelTo(now()->addMinutes(31));
        artisan('shop:recalc-prices', ['--boundaries' => true])->assertSuccessful();

        expect(gpRow($product, $this->group)['final'])->toBe('900.00');

        $this->travelTo(now()->addDays(1));
        $rule->update(['ends_at' => now()->subMinute()]);
        artisan('shop:recalc-prices', ['--all' => true])->assertSuccessful();

        expect(gpRow($product, $this->group))->toBeNull();
        @unlink(RecalcPricesCommand::lastRunPath());
    });

    it('does not touch a rule that is not bound to anything with the group rule scope of other products', function (): void {
        $in = gpProduct('1000.00', ['brand' => 'Север']);
        $out = gpProduct('1000.00', ['brand' => 'Уют']);

        app(PriceRuleSaver::class)->save(null, gpRuleState(['scope' => 'brands', 'brands' => ['Север'], 'group_ids' => [$this->group->id]]));

        expect(gpRow($in, $this->group)['final'])->toBe('900.00')
            ->and(gpRow($out, $this->group))->toBeNull();
    });

    it('switches off a rule bound only to a group when that group is deleted instead of widening it to everyone', function (): void {
        $product = gpProduct('1000.00');
        $second = CustomerGroup::factory()->create();
        $only = app(PriceRuleSaver::class)->save(null, gpRuleState(['name' => 'Только группа', 'group_ids' => [$this->group->id]]));
        $both = app(PriceRuleSaver::class)->save(null, gpRuleState(['name' => 'Две группы', 'value' => '5', 'group_ids' => [$this->group->id, $second->id]]));

        $this->group->delete();

        expect($only->fresh()->is_active)->toBeFalse()
            ->and($both->fresh()->is_active)->toBeTrue()
            ->and($product->fresh()->final_price)->toBe('1000.00')
            ->and($both->fresh()->groups()->pluck('customer_groups.id')->all())->toBe([$second->id]);
    });
});

describe('rounding (GP-19)', function (): void {
    beforeEach(function (): void {
        $settings = app(PricingSettings::class);
        $settings->rounding_step = RoundingStep::Ten;
        $settings->rounding_direction = RoundingDirection::Up;
        $settings->rounding_scope = RoundingScope::Changed;
        $settings->save();
    });

    it('rounds the discounted price when the setting is on', function (): void {
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '12.64']);

        expect(gpRow($product, $this->group)['final'])->toBe('880.00');
    });

    it('keeps kopecks when the setting is off', function (): void {
        $settings = app(PricingSettings::class);
        $settings->group_prices_rounding = false;
        $settings->save();
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '12.64']);

        expect(gpRow($product, $this->group)['final'])->toBe('873.60');
    });
});

describe('cards with variants', function (): void {
    beforeEach(function (): void {
        $this->size = optionWith('Размер', ['S', 'M', 'L']);
        $this->card = variantCard([], $this->size);
        $this->s = variantOf($this->card, [$this->size->values[0]], ['base_price' => '500.00']);
        $this->m = variantOf($this->card, [$this->size->values[1]], ['base_price' => '700.00']);
        $this->group->update(['default_discount_percent' => '10']);
    });

    it('stores a row per variant and the minimum on the card (variant_key 0)', function (): void {
        expect(gpRow($this->s, $this->group)['final'])->toBe('450.00')
            ->and(gpRow($this->m, $this->group)['final'])->toBe('630.00')
            ->and(gpRow($this->card, $this->group)['final'])->toBe('450.00')
            ->and($this->card->fresh()->final_price)->toBe('500.00');
    });

    it('uses the exact price of a variant in the minimum', function (): void {
        gpTier($this->m, $this->group, 1, '300.00');
        $this->recalc->recalculate([$this->card->id]);

        expect(gpRow($this->m, $this->group)['final'])->toBe('300.00')
            ->and(gpRow($this->card, $this->group)['final'])->toBe('300.00');
    });

    it('counts a variant without its own row at its retail price', function (): void {
        // The group has an exact price on S only; M (no row because of rounding down to retail) counts at retail.
        $other = CustomerGroup::factory()->create();
        gpTier($this->s, $other, 1, '500.00');
        gpTier($this->m, $other, 1, '650.00');

        $this->recalc->recalculate([$this->card->id]);

        // S equals retail: no row; M differs. Minimum = min(S retail 500, M 650) = 500 = card retail: no card row.
        expect(gpRow($this->s, $other))->toBeNull()
            ->and(gpRow($this->m, $other)['final'])->toBe('650.00')
            ->and(gpRow($this->card, $other))->toBeNull();
    });

    it('stores the card row only when it differs from the card retail price', function (): void {
        $this->group->update(['default_discount_percent' => '0']);

        expect(gpRow($this->card, $this->group))->toBeNull()
            ->and(ProductGroupPrice::query()->count())->toBe(0);
    });

    it('ignores inactive variants unless every variant is inactive', function (): void {
        $this->s->update(['is_active' => false]);

        expect(gpRow($this->card, $this->group)['final'])->toBe('630.00');

        $this->m->update(['is_active' => false]);

        expect(gpRow($this->card, $this->group)['final'])->toBe('450.00');
    });

    it('follows a new variant, a price change and the deletion of a variant', function (): void {
        $l = variantOf($this->card, [$this->size->values[2]], ['base_price' => '200.00']);
        expect(gpRow($this->card, $this->group)['final'])->toBe('180.00');

        $l->update(['base_price' => '800.00']);
        expect(gpRow($this->card, $this->group)['final'])->toBe('450.00');

        $this->s->delete();
        expect(gpRow($this->card, $this->group)['final'])->toBe('630.00')
            ->and(gpRow($this->s, $this->group))->toBeNull();
    });

    it('applies a group rule to variants with the scope of the card', function (): void {
        $this->group->update(['default_discount_percent' => '0']);
        app(PriceRuleSaver::class)->save(null, gpRuleState(['group_ids' => [$this->group->id], 'scope' => 'brands', 'brands' => [(string) $this->card->brand]]));

        expect(gpRow($this->s, $this->group)['final'])->toBe('450.00')
            ->and(gpRow($this->card, $this->group)['final'])->toBe('450.00');
    });

    it('removes the rows of a trashed card', function (): void {
        $this->card->delete();
        $this->recalc->recalculate([$this->card->id]);

        expect(ProductGroupPrice::query()->where('product_id', $this->card->id)->count())->toBe(0);
    });
});

describe('background job', function (): void {
    it('recalculates retail and groups of the ids (RecalculatePrices)', function (): void {
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '10']);
        DB::table('product_group_prices_final')->update(['final_price' => '1.00']);
        DB::table('products')->update(['final_price' => '5.00']);

        dispatch_sync(new RecalculatePrices([$product->id]));

        expect($product->fresh()->final_price)->toBe('1000.00')
            ->and(gpRow($product, $this->group)['final'])->toBe('900.00');
    });

    it('recalculates only the given groups and leaves retail alone', function (): void {
        $product = gpProduct('1000.00');
        $second = CustomerGroup::factory()->discount(50)->create();
        $this->group->update(['default_discount_percent' => '10']);
        DB::table('product_group_prices_final')->update(['final_price' => '1.00']);
        DB::table('products')->update(['final_price' => '5.00']);

        dispatch_sync(new RecalculatePrices([$product->id], [$this->group->id]));

        expect($product->fresh()->final_price)->toBe('5.00')
            ->and(gpRow($product, $this->group)['final'])->toBe('900.00')
            ->and(gpRow($product, $second)['final'])->toBe('1.00');
    });

    it('registers the group jobs in the progress banner and finishes it', function (): void {
        Queue::fake();
        gpProduct();
        gpProduct();

        $result = app(PriceRuleRecalculator::class)->dispatchForGroups([$this->group->id]);

        expect($result)->toBe(['products' => 2, 'jobs' => 1])
            ->and(app(RecalculationProgress::class)->current()['total'])->toBe(2);

        Queue::assertPushed(RecalculatePrices::class, fn (RecalculatePrices $job): bool => $job->groupIds === [$this->group->id]);
    });

    it('queues a group discount change as a group-only job', function (): void {
        Queue::fake();
        gpProduct();

        $this->group->update(['default_discount_percent' => '10']);

        Queue::assertPushed(RecalculatePrices::class, fn (RecalculatePrices $job): bool => $job->groupIds === [$this->group->id]);
    });

    it('does not queue anything when an unrelated group field changes', function (): void {
        Queue::fake();
        gpProduct();

        $this->group->update(['name' => 'Другое имя']);

        Queue::assertNothingPushed();
    });

    it('covers the groups in the synchronous command as well', function (): void {
        $product = gpProduct('1000.00');
        $this->group->update(['default_discount_percent' => '10']);
        DB::table('product_group_prices_final')->delete();

        artisan('shop:recalc-prices', ['--all' => true, '--sync' => true])->assertSuccessful();

        expect(gpRow($product, $this->group)['final'])->toBe('900.00');
    });

    it('writes in batches: upserts and deletions of 500 rows at most (GP-07)', function (): void {
        $ids = Product::factory()->count(501)->create(['base_price' => '1000.00'])->pluck('id')->all();
        DB::table('customer_groups')->where('id', $this->group->id)->update(['default_discount_percent' => '10']);

        DB::enableQueryLog();
        $changed = $this->recalc->recalculate($ids);
        $upserts = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_starts_with($q['query'], 'insert into "product_group_prices_final"') || str_starts_with($q['query'], 'insert into `product_group_prices_final`'))->count();
        DB::disableQueryLog();

        expect($changed)->toBe(501)->and($upserts)->toBe(2)->and(ProductGroupPrice::query()->count())->toBe(501);

        DB::table('customer_groups')->where('id', $this->group->id)->update(['default_discount_percent' => '0']);

        expect($this->recalc->recalculate($ids))->toBe(501)->and(ProductGroupPrice::query()->count())->toBe(0);
    });
});
