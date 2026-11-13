<?php

declare(strict_types=1);

use App\Enums\PriceChangeSource;
use App\Models\CustomerGroup;
use App\Models\PriceHistory;
use App\Models\ProductPriceTier;
use App\Models\User;
use App\Services\Pricing\PriceChangeContext;
use App\Services\Pricing\PriceTierService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->tiers = app(PriceTierService::class);
    $this->group = CustomerGroup::factory()->create();
    $this->product = gpProduct('1000.00');
});

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<string>
 */
function tierCodes(array $issues): array
{
    return array_map(fn ($issue): string => $issue->code, $issues);
}

describe('validation (GP-14)', function (): void {
    it('accepts valid rows and normalizes them', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [
            ['min_qty' => '50', 'price' => '700,50'],
            ['min_qty' => 10, 'price' => '800'],
            ['min_qty' => '', 'price' => ''],
        ]);

        expect($result->passes())->toBeTrue()
            ->and($result->rows)->toBe([['min_qty' => 10, 'price' => 80_000], ['min_qty' => 50, 'price' => 70_050]])
            ->and($result->warnings)->toBe([]);
    });

    it('rejects a quantity that is not an integer of at least 1', function (mixed $qty): void {
        $result = $this->tiers->validate($this->product, $this->group, [['min_qty' => $qty, 'price' => '100']]);

        expect(tierCodes($result->errors))->toBe(['invalid_qty']);
    })->with([0, -3, '1.5', 'abc', '1e3', null]);

    it('rejects a price that is not above zero', function (mixed $price): void {
        $result = $this->tiers->validate($this->product, $this->group, [['min_qty' => 5, 'price' => $price]]);

        expect(tierCodes($result->errors))->toBe(['invalid_price']);
    })->with(['0', '0,00', '-5', 'free', null, '10000000000']);

    it('rejects a duplicate quantity', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [
            ['min_qty' => 5, 'price' => '900'],
            ['min_qty' => '5', 'price' => '800'],
        ]);

        expect(tierCodes($result->errors))->toBe(['duplicate_qty']);
    });

    it('rejects a price that grows with the quantity, the exact price included', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [
            ['min_qty' => 1, 'price' => '800'],
            ['min_qty' => 10, 'price' => '900'],
            ['min_qty' => 50, 'price' => '900'],
        ]);

        expect(tierCodes($result->errors))->toBe(['price_grows'])
            ->and($result->errors[0]->minQty)->toBe(10);
    });

    it('allows an equal price on the next tier', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [
            ['min_qty' => 10, 'price' => '900'],
            ['min_qty' => 20, 'price' => '900'],
        ]);

        expect($result->passes())->toBeTrue();
    });

    it('allows at most 10 rows per item and group', function (): void {
        $rows = fn (int $count) => array_map(fn (int $i): array => ['min_qty' => $i, 'price' => (string) (1000 - $i)], range(1, $count));

        expect($this->tiers->validate($this->product, $this->group, $rows(10))->passes())->toBeTrue()
            ->and(tierCodes($this->tiers->validate($this->product, $this->group, $rows(11))->errors))->toBe(['too_many']);
    });

    it('refuses tiers on a card with variants', function (): void {
        $card = variantCard([], optionWith('Размер', ['S']));

        $result = $this->tiers->validate($card, $this->group, [['min_qty' => 5, 'price' => '100']]);

        expect(tierCodes($result->errors))->toBe(['card_has_variants']);
    });

    it('warns, but does not fail, about a tier above the retail price', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [['min_qty' => 5, 'price' => '1200']]);

        expect($result->passes())->toBeTrue()
            ->and(tierCodes($result->warnings))->toContain('above_retail', 'above_group_price');
    });

    it('warns about a tier above the group price without tiers (discount of the group)', function (): void {
        $discounted = CustomerGroup::factory()->discount(20)->create();

        // Retail 1 000, group price 800: a tier at 900 is below retail but above what the group pays for one piece.
        $result = $this->tiers->validate($this->product, $discounted, [['min_qty' => 5, 'price' => '900']]);

        expect($result->passes())->toBeTrue()
            ->and(tierCodes($result->warnings))->toBe(['above_group_price']);
    });

    it('compares a tier with the exact price of the same set', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [
            ['min_qty' => 1, 'price' => '900'],
            ['min_qty' => 10, 'price' => '900'],
            ['min_qty' => 20, 'price' => '850'],
        ]);

        expect($result->warnings)->toBe([]);
    });

    it('has no warnings for a sensible set', function (): void {
        $result = $this->tiers->validate($this->product, $this->group, [
            ['min_qty' => 10, 'price' => '900'],
            ['min_qty' => 50, 'price' => '800'],
        ]);

        expect($result->warnings)->toBe([]);
    });
});

describe('saving', function (): void {
    it('stores tiers and the exact price', function (): void {
        $result = $this->tiers->replace($this->product, $this->group, [
            ['min_qty' => 1, 'price' => '950'],
            ['min_qty' => 10, 'price' => '900'],
        ]);

        expect($result->created)->toBe(2)
            ->and($this->tiers->rows($this->product, $this->group))->toBe([['min_qty' => 1, 'price' => '950.00'], ['min_qty' => 10, 'price' => '900.00']])
            ->and(ProductPriceTier::query()->first()->variant_key)->toBe(0);
    });

    it('replaces the set: updates, inserts and deletes', function (): void {
        $this->tiers->replace($this->product, $this->group, [
            ['min_qty' => 10, 'price' => '900'],
            ['min_qty' => 50, 'price' => '800'],
        ]);

        $result = $this->tiers->replace($this->product, $this->group, [
            ['min_qty' => 10, 'price' => '850'],
            ['min_qty' => 20, 'price' => '800'],
        ]);

        expect([$result->created, $result->updated, $result->deleted])->toBe([1, 1, 1])
            ->and($this->tiers->rows($this->product, $this->group))->toBe([['min_qty' => 10, 'price' => '850.00'], ['min_qty' => 20, 'price' => '800.00']]);
    });

    it('changes nothing when the same set is saved again', function (): void {
        $rows = [['min_qty' => 10, 'price' => '900']];
        $this->tiers->replace($this->product, $this->group, $rows);

        $result = $this->tiers->replace($this->product, $this->group, $rows);

        expect($result->changed())->toBeFalse()
            ->and(PriceHistory::query()->count())->toBe(1);
    });

    it('throws a validation exception and writes nothing for invalid rows', function (): void {
        expect(fn () => $this->tiers->replace($this->product, $this->group, [['min_qty' => 5, 'price' => '0']]))
            ->toThrow(ValidationException::class)
            ->and(ProductPriceTier::query()->count())->toBe(0);
    });

    it('clears the set of one group only', function (): void {
        $other = CustomerGroup::factory()->create();
        $second = gpProduct();
        $this->tiers->replace($this->product, $other, [['min_qty' => 5, 'price' => '900']]);
        $this->tiers->replace($second, $this->group, [['min_qty' => 5, 'price' => '900']]);
        $this->tiers->replace($this->product, $this->group, [['min_qty' => 10, 'price' => '800']]);

        $result = $this->tiers->clear($this->product, $this->group);

        expect($result->deleted)->toBe(1)->and(ProductPriceTier::query()->count())->toBe(2);
    });

    it('returns the warnings of a saved set', function (): void {
        $result = $this->tiers->replace($this->product, $this->group, [['min_qty' => 5, 'price' => '1200']]);

        expect(tierCodes($result->warnings))->toContain('above_retail')
            ->and($this->tiers->rows($this->product, $this->group))->toHaveCount(1);
    });

    it('stores tiers of a variant with the variant key', function (): void {
        $size = optionWith('Размер', ['S', 'M']);
        $card = variantCard([], $size);
        $s = variantOf($card, [$size->values[0]], ['base_price' => '500.00']);
        $m = variantOf($card, [$size->values[1]], ['base_price' => '700.00']);

        $this->tiers->replace($s, $this->group, [['min_qty' => 10, 'price' => '400']]);
        $this->tiers->replace($m, $this->group, [['min_qty' => 10, 'price' => '600']]);

        expect($this->tiers->rows($s, $this->group))->toBe([['min_qty' => 10, 'price' => '400.00']])
            ->and(ProductPriceTier::query()->where('variant_id', $s->id)->first()->variant_key)->toBe($s->id)
            ->and(ProductPriceTier::query()->count())->toBe(2);
    });

    it('recomputes the group price row when the exact price changes', function (): void {
        $this->group->update(['default_discount_percent' => '10']);

        $this->tiers->replace($this->product, $this->group, [['min_qty' => 1, 'price' => '700']]);
        expect(gpRow($this->product, $this->group)['final'])->toBe('700.00');

        $this->tiers->clear($this->product, $this->group);
        expect(gpRow($this->product, $this->group)['final'])->toBe('900.00');
    });

    it('can leave the recalculation to the caller', function (): void {
        $this->tiers->replace($this->product, $this->group, [['min_qty' => 1, 'price' => '700']], recalculate: false);

        expect(gpRow($this->product, $this->group))->toBeNull();
    });
});

describe('price history (GP-16)', function (): void {
    it('records created, changed and removed rows with group, quantity and variant', function (): void {
        $this->tiers->replace($this->product, $this->group, [
            ['min_qty' => 1, 'price' => '950'],
            ['min_qty' => 10, 'price' => '900'],
        ]);
        $this->tiers->replace($this->product, $this->group, [['min_qty' => 10, 'price' => '850']]);

        $rows = PriceHistory::query()->orderBy('id')->get();

        expect($rows)->toHaveCount(4)
            ->and($rows->map(fn (PriceHistory $row): array => [$row->field, $row->min_qty, $row->old_value, $row->new_value])->all())->toBe([
                ['group_price', 1, null, '950.00'],
                ['group_tier', 10, null, '900.00'],
                ['group_price', 1, '950.00', null],
                ['group_tier', 10, '900.00', '850.00'],
            ])
            ->and($rows->pluck('customer_group_id')->unique()->all())->toBe([$this->group->id])
            ->and($rows->pluck('product_id')->unique()->all())->toBe([$this->product->id])
            ->and($rows[0]->variant_id)->toBeNull();
    });

    it('records the variant of a variant tier', function (): void {
        $size = optionWith('Размер', ['S']);
        $card = variantCard([], $size);
        $s = variantOf($card, [$size->values[0]], ['base_price' => '500.00']);

        $this->tiers->replace($s, $this->group, [['min_qty' => 5, 'price' => '400']]);

        $row = PriceHistory::query()->first();

        expect($row->variant_id)->toBe($s->id)->and($row->product_id)->toBe($card->id);
    });

    it('takes source and user from the price change context', function (): void {
        $user = User::factory()->admin()->create();

        app(PriceChangeContext::class)->run(PriceChangeSource::Import, null, fn () => $this->tiers->replace($this->product, $this->group, [['min_qty' => 5, 'price' => '900']]), $user->id);

        $row = PriceHistory::query()->first();

        expect($row->source)->toBe(PriceChangeSource::Import)->and($row->user_id)->toBe($user->id);
    });

    it('does not record price changes driven by discounts and rules', function (): void {
        $this->group->update(['default_discount_percent' => '10']);
        $this->group->update(['default_discount_percent' => '20']);

        expect(PriceHistory::query()->count())->toBe(0);
    });

    it('keeps the history when the group is deleted (group set to null)', function (): void {
        $this->tiers->replace($this->product, $this->group, [['min_qty' => 5, 'price' => '900']]);

        $this->group->delete();

        expect(PriceHistory::query()->count())->toBe(1)->and(PriceHistory::query()->first()->customer_group_id)->toBeNull()
            ->and(ProductPriceTier::query()->count())->toBe(0);
    });
});
