<?php

declare(strict_types=1);

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\ProductFlag;
use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PriceInput;
use App\Services\Pricing\PriceResult;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PricingConfig;
use Carbon\CarbonImmutable;

/**
 * Money in these tests is written in kopecks: 100_000 = 1 000 RUB.
 */
function pcInput(int $base = 100_000, array $overrides = []): PriceInput
{
    return new PriceInput(...array_merge([
        'productId' => 1,
        'categoryId' => 10,
        'brand' => 'Север',
        'isFeatured' => false,
        'isNew' => false,
        'basePrice' => $base,
        'categoryPathIds' => [10, 5, 1],
    ], $overrides));
}

function pcRule(int $id, PriceRuleAction $action, string $value, array $overrides = []): PriceRuleData
{
    return new PriceRuleData(...array_merge([
        'id' => $id,
        'name' => 'Rule '.$id,
        'action' => $action,
        'value' => $value,
    ], $overrides));
}

/**
 * @param  list<PriceRuleData>  $rules
 */
function pcCalc(PriceInput $input, array $rules = [], ?PricingConfig $config = null, ?CarbonImmutable $now = null): PriceResult
{
    return (new PriceCalculator)->calculate($input, $rules, $now ?? CarbonImmutable::parse('2026-06-01 12:00:00'), $config ?? new PricingConfig);
}

it('returns the base price when there are no rules', function (): void {
    $result = pcCalc(pcInput(123_456));

    expect($result->finalPrice)->toBe(123_456)
        ->and($result->displayOldPrice)->toBeNull()
        ->and($result->appliedRuleIds)->toBe([]);
});

it('applies markup_percent', function (): void {
    expect(pcCalc(pcInput(), [pcRule(1, PriceRuleAction::MarkupPercent, '20')])->finalPrice)->toBe(120_000);
});

it('applies markup_fixed', function (): void {
    expect(pcCalc(pcInput(), [pcRule(1, PriceRuleAction::MarkupFixed, '150.50')])->finalPrice)->toBe(115_050);
});

it('applies discount_percent', function (): void {
    expect(pcCalc(pcInput(), [pcRule(1, PriceRuleAction::DiscountPercent, '10')])->finalPrice)->toBe(90_000);
});

it('applies discount_fixed', function (): void {
    expect(pcCalc(pcInput(), [pcRule(1, PriceRuleAction::DiscountFixed, '250')])->finalPrice)->toBe(75_000);
});

it('applies cost_markup_percent to the cost price instead of the base price', function (): void {
    $result = pcCalc(pcInput(100_000, ['costPrice' => 60_000]), [pcRule(1, PriceRuleAction::CostMarkupPercent, '50')]);

    expect($result->finalPrice)->toBe(90_000)
        ->and($result->appliedRuleIds)->toBe([1]);
});

it('skips cost_markup_percent without a cost price and explains why', function (): void {
    $result = pcCalc(pcInput(), [pcRule(1, PriceRuleAction::CostMarkupPercent, '50')]);

    expect($result->finalPrice)->toBe(100_000)
        ->and($result->appliedRuleIds)->toBe([])
        ->and($result->explanation->toLines()[1])->toContain('нет закупочной цены');
});

it('does not let a skipped cost markup stop the phase', function (): void {
    $rules = [
        pcRule(1, PriceRuleAction::CostMarkupPercent, '50', ['priority' => 10, 'stopProcessing' => true]),
        pcRule(2, PriceRuleAction::MarkupFixed, '100'),
    ];

    expect(pcCalc(pcInput(), $rules)->finalPrice)->toBe(110_000);
});

it('applies markups before discounts regardless of priority', function (): void {
    $rules = [
        pcRule(1, PriceRuleAction::DiscountFixed, '100', ['priority' => 100]),
        pcRule(2, PriceRuleAction::MarkupPercent, '10', ['priority' => 0]),
    ];

    $result = pcCalc(pcInput(), $rules);

    // Markup first: 1000 * 1.1 - 100 = 1000 (discount first would give 990).
    expect($result->finalPrice)->toBe(100_000)
        ->and($result->appliedRuleIds)->toBe([2, 1]);
});

it('orders rules of a phase by priority descending then id ascending', function (): void {
    $rules = [
        pcRule(5, PriceRuleAction::MarkupFixed, '10', ['priority' => 1]),
        pcRule(3, PriceRuleAction::MarkupFixed, '10', ['priority' => 5]),
        pcRule(2, PriceRuleAction::MarkupFixed, '10', ['priority' => 1]),
        pcRule(9, PriceRuleAction::MarkupFixed, '10', ['priority' => 5]),
    ];

    expect(pcCalc(pcInput(), $rules)->appliedRuleIds)->toBe([3, 9, 2, 5]);
});

it('makes the order of a percent and a fixed markup matter', function (): void {
    $percentFirst = [
        pcRule(1, PriceRuleAction::MarkupPercent, '10', ['priority' => 2]),
        pcRule(2, PriceRuleAction::MarkupFixed, '100', ['priority' => 1]),
    ];
    $fixedFirst = [
        pcRule(1, PriceRuleAction::MarkupPercent, '10', ['priority' => 1]),
        pcRule(2, PriceRuleAction::MarkupFixed, '100', ['priority' => 2]),
    ];

    // 1000 * 1.1 + 100 = 1200, but (1000 + 100) * 1.1 = 1210.
    expect(pcCalc(pcInput(), $percentFirst)->finalPrice)->toBe(120_000)
        ->and(pcCalc(pcInput(), $fixedFirst)->finalPrice)->toBe(121_000);
});

it('stops a phase after a stop_processing rule but still runs the other phase', function (): void {
    $rules = [
        pcRule(1, PriceRuleAction::MarkupFixed, '100', ['priority' => 10, 'stopProcessing' => true]),
        pcRule(2, PriceRuleAction::MarkupFixed, '500', ['priority' => 1]),
        pcRule(3, PriceRuleAction::DiscountFixed, '200', ['priority' => 1]),
    ];

    $result = pcCalc(pcInput(), $rules);

    expect($result->finalPrice)->toBe(100_000 + 10_000 - 20_000)
        ->and($result->appliedRuleIds)->toBe([1, 3]);
});

it('stops the discount phase without affecting the markup phase', function (): void {
    $rules = [
        pcRule(1, PriceRuleAction::MarkupFixed, '100', ['priority' => 1]),
        pcRule(2, PriceRuleAction::DiscountFixed, '50', ['priority' => 5, 'stopProcessing' => true]),
        pcRule(3, PriceRuleAction::DiscountFixed, '70', ['priority' => 1]),
        pcRule(4, PriceRuleAction::MarkupFixed, '10', ['priority' => 0]),
    ];

    expect(pcCalc(pcInput(), $rules)->appliedRuleIds)->toBe([1, 4, 2]);
});

describe('periods', function (): void {
    $now = CarbonImmutable::parse('2026-06-01 12:00:00');
    $rule = fn (?CarbonImmutable $starts, ?CarbonImmutable $ends): PriceRuleData => pcRule(1, PriceRuleAction::MarkupFixed, '100', ['startsAt' => $starts, 'endsAt' => $ends]);

    it('ignores a rule that has not started yet', function () use ($now, $rule): void {
        expect(pcCalc(pcInput(), [$rule($now->addSecond(), null)], null, $now)->finalPrice)->toBe(100_000);
    });

    it('ignores a rule that has already ended', function () use ($now, $rule): void {
        expect(pcCalc(pcInput(), [$rule(null, $now->subSecond())], null, $now)->finalPrice)->toBe(100_000);
    });

    it('includes the start boundary and excludes the end boundary', function () use ($now, $rule): void {
        expect(pcCalc(pcInput(), [$rule($now, null)], null, $now)->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(), [$rule(null, $now)], null, $now)->finalPrice)->toBe(100_000)
            ->and(pcCalc(pcInput(), [$rule(null, $now->addSecond())], null, $now)->finalPrice)->toBe(110_000);
    });

    it('applies a rule inside its period', function () use ($now, $rule): void {
        expect(pcCalc(pcInput(), [$rule($now->subDay(), $now->addDay())], null, $now)->finalPrice)->toBe(110_000);
    });
});

describe('scopes', function (): void {
    $markup = fn (array $overrides): PriceRuleData => pcRule(1, PriceRuleAction::MarkupFixed, '100', $overrides);

    it('matches all products', function () use ($markup): void {
        expect(pcCalc(pcInput(), [$markup(['scope' => PriceRuleScope::All])])->finalPrice)->toBe(110_000);
    });

    it('matches the exact category with and without subcategories', function () use ($markup): void {
        $rule = fn (bool $sub) => $markup(['scope' => PriceRuleScope::Categories, 'categoryIds' => [10], 'includeSubcategories' => $sub]);

        expect(pcCalc(pcInput(), [$rule(true)])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(), [$rule(false)])->finalPrice)->toBe(110_000);
    });

    it('matches a parent category only with subcategories', function () use ($markup): void {
        $rule = fn (bool $sub) => $markup(['scope' => PriceRuleScope::Categories, 'categoryIds' => [5], 'includeSubcategories' => $sub]);

        expect(pcCalc(pcInput(), [$rule(true)])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(), [$rule(false)])->finalPrice)->toBe(100_000);
    });

    it('does not match an unrelated category', function () use ($markup): void {
        $rule = $markup(['scope' => PriceRuleScope::Categories, 'categoryIds' => [77], 'includeSubcategories' => true]);

        expect(pcCalc(pcInput(), [$rule])->finalPrice)->toBe(100_000);
    });

    it('matches a category when no ancestor list is given', function () use ($markup): void {
        $rule = $markup(['scope' => PriceRuleScope::Categories, 'categoryIds' => [10]]);

        expect(pcCalc(pcInput(100_000, ['categoryPathIds' => []]), [$rule])->finalPrice)->toBe(110_000);
    });

    it('matches selected products', function () use ($markup): void {
        $rule = $markup(['scope' => PriceRuleScope::Products, 'productIds' => [1, 2]]);

        expect(pcCalc(pcInput(), [$rule])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(100_000, ['productId' => 3]), [$rule])->finalPrice)->toBe(100_000)
            ->and(pcCalc(pcInput(100_000, ['productId' => null]), [$rule])->finalPrice)->toBe(100_000);
    });

    it('matches brands case-insensitively', function () use ($markup): void {
        $rule = $markup(['scope' => PriceRuleScope::Brands, 'brands' => ['север', 'nordic']]);

        expect(pcCalc(pcInput(100_000, ['brand' => 'СЕВЕР']), [$rule])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(100_000, ['brand' => ' Nordic ']), [$rule])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(100_000, ['brand' => 'Аврора']), [$rule])->finalPrice)->toBe(100_000)
            ->and(pcCalc(pcInput(100_000, ['brand' => null]), [$rule])->finalPrice)->toBe(100_000);
    });

    it('matches flags', function () use ($markup): void {
        $featured = $markup(['scope' => PriceRuleScope::Flags, 'flags' => [ProductFlag::Featured]]);
        $either = $markup(['scope' => PriceRuleScope::Flags, 'flags' => [ProductFlag::Featured, ProductFlag::New]]);

        expect(pcCalc(pcInput(100_000, ['isFeatured' => true]), [$featured])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(100_000, ['isNew' => true]), [$featured])->finalPrice)->toBe(100_000)
            ->and(pcCalc(pcInput(100_000, ['isNew' => true]), [$either])->finalPrice)->toBe(110_000)
            ->and(pcCalc(pcInput(), [$either])->finalPrice)->toBe(100_000);
    });
});

describe('fixed price', function (): void {
    it('disables all rules and rounding', function (): void {
        $config = new PricingConfig(RoundingStep::Hundred, RoundingDirection::Up, true, RoundingScope::All);
        $rules = [pcRule(1, PriceRuleAction::MarkupPercent, '50'), pcRule(2, PriceRuleAction::DiscountPercent, '50', ['showAsDiscount' => true])];

        $result = pcCalc(pcInput(100_000, ['fixedPrice' => 85_050]), $rules, $config);

        expect($result->finalPrice)->toBe(85_050)
            ->and($result->appliedRuleIds)->toBe([])
            ->and($result->displayOldPrice)->toBeNull();
    });

    it('shows compare_at_price as old price only when greater than the fixed price', function (): void {
        $rule = [pcRule(1, PriceRuleAction::DiscountPercent, '50', ['showAsDiscount' => true])];

        expect(pcCalc(pcInput(100_000, ['fixedPrice' => 80_000, 'compareAtPrice' => 95_000]), $rule)->displayOldPrice)->toBe(95_000)
            ->and(pcCalc(pcInput(100_000, ['fixedPrice' => 80_000, 'compareAtPrice' => 80_000]), $rule)->displayOldPrice)->toBeNull()
            ->and(pcCalc(pcInput(100_000, ['fixedPrice' => 80_000]), $rule)->displayOldPrice)->toBeNull();
    });
});

describe('rounding', function (): void {
    $cases = [
        // [step, direction, price in kopecks, expected]
        [RoundingStep::One, RoundingDirection::Up, 100_001, 100_100],
        [RoundingStep::One, RoundingDirection::Up, 100_100, 100_100],
        [RoundingStep::One, RoundingDirection::Nearest, 100_049, 100_000],
        [RoundingStep::One, RoundingDirection::Nearest, 100_050, 100_100],
        [RoundingStep::One, RoundingDirection::Down, 100_099, 100_000],
        [RoundingStep::Ten, RoundingDirection::Up, 100_100, 101_000],
        [RoundingStep::Ten, RoundingDirection::Up, 101_000, 101_000],
        [RoundingStep::Ten, RoundingDirection::Nearest, 100_400, 100_000],
        [RoundingStep::Ten, RoundingDirection::Nearest, 100_500, 101_000],
        [RoundingStep::Ten, RoundingDirection::Down, 100_999, 100_000],
        [RoundingStep::Fifty, RoundingDirection::Up, 100_100, 105_000],
        [RoundingStep::Fifty, RoundingDirection::Nearest, 102_400, 100_000],
        [RoundingStep::Fifty, RoundingDirection::Nearest, 102_500, 105_000],
        [RoundingStep::Fifty, RoundingDirection::Down, 104_999, 100_000],
        [RoundingStep::Hundred, RoundingDirection::Up, 100_100, 110_000],
        [RoundingStep::Hundred, RoundingDirection::Nearest, 104_999, 100_000],
        [RoundingStep::Hundred, RoundingDirection::Nearest, 105_000, 110_000],
        [RoundingStep::Hundred, RoundingDirection::Down, 109_999, 100_000],
    ];

    foreach ($cases as [$step, $direction, $price, $expected]) {
        it("rounds {$price} kopecks to {$step->name} {$direction->name} = {$expected}", function () use ($step, $direction, $price, $expected): void {
            $config = new PricingConfig($step, $direction);
            // A +0 RUB markup counts as an applied rule, so rounding is triggered.
            $rule = pcRule(1, PriceRuleAction::MarkupFixed, '0');

            expect(pcCalc(pcInput($price), [$rule], $config)->finalPrice)->toBe($expected);
        });
    }

    it('does not round without rules when the scope is "changed"', function (): void {
        $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up, false, RoundingScope::Changed);

        expect(pcCalc(pcInput(100_101), [], $config)->finalPrice)->toBe(100_101);
    });

    it('rounds untouched prices when the scope is "all"', function (): void {
        $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up, false, RoundingScope::All);

        expect(pcCalc(pcInput(100_101), [], $config)->finalPrice)->toBe(101_000);
    });

    it('does not round when the step is "none"', function (): void {
        $config = new PricingConfig(RoundingStep::None, RoundingDirection::Up, true, RoundingScope::All);

        expect(pcCalc(pcInput(100_101), [], $config)->finalPrice)->toBe(100_101);
    });

    it('does not treat a skipped rule as a changed price', function (): void {
        $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up, false, RoundingScope::Changed);

        expect(pcCalc(pcInput(100_101), [pcRule(1, PriceRuleAction::CostMarkupPercent, '10')], $config)->finalPrice)->toBe(100_101);
    });
});

describe('psychological price', function (): void {
    it('subtracts 1 RUB after rounding for steps of 10 RUB and more', function (RoundingStep $step, int $price, int $expected): void {
        $config = new PricingConfig($step, RoundingDirection::Up, true, RoundingScope::All);

        expect(pcCalc(pcInput($price), [], $config)->finalPrice)->toBe($expected);
    })->with([
        [RoundingStep::Ten, 100_100, 100_900],
        [RoundingStep::Fifty, 100_100, 104_900],
        [RoundingStep::Hundred, 100_100, 109_900],
    ]);

    it('does nothing for steps below 10 RUB', function (): void {
        $config = new PricingConfig(RoundingStep::One, RoundingDirection::Up, true, RoundingScope::All);

        expect(pcCalc(pcInput(100_100), [], $config)->finalPrice)->toBe(100_100);
    });

    it('does not go below zero', function (): void {
        $config = new PricingConfig(RoundingStep::Hundred, RoundingDirection::Down, true, RoundingScope::All);

        expect(pcCalc(pcInput(5_000), [], $config)->finalPrice)->toBe(0);
    });
});

describe('old price', function (): void {
    it('uses compare_at_price when greater than the final price', function (): void {
        expect(pcCalc(pcInput(100_000, ['compareAtPrice' => 150_000]))->displayOldPrice)->toBe(150_000);
    });

    it('is empty when compare_at_price is not greater than the final price', function (): void {
        expect(pcCalc(pcInput(100_000, ['compareAtPrice' => 100_000]))->displayOldPrice)->toBeNull()
            ->and(pcCalc(pcInput(100_000, ['compareAtPrice' => 50_000]))->displayOldPrice)->toBeNull();
    });

    it('uses the price before discounts for a show_as_discount rule', function (): void {
        $rules = [
            pcRule(1, PriceRuleAction::MarkupPercent, '20'),
            pcRule(2, PriceRuleAction::DiscountPercent, '10', ['showAsDiscount' => true]),
        ];

        $result = pcCalc(pcInput(), $rules);

        expect($result->finalPrice)->toBe(108_000)
            ->and($result->displayOldPrice)->toBe(120_000);
    });

    it('takes the greater of compare_at_price and the price before discounts', function (): void {
        $rule = [pcRule(1, PriceRuleAction::DiscountPercent, '10', ['showAsDiscount' => true])];

        expect(pcCalc(pcInput(100_000, ['compareAtPrice' => 130_000]), $rule)->displayOldPrice)->toBe(130_000)
            ->and(pcCalc(pcInput(100_000, ['compareAtPrice' => 95_000]), $rule)->displayOldPrice)->toBe(100_000);
    });

    it('shows no old price for a discount without show_as_discount', function (): void {
        $result = pcCalc(pcInput(), [pcRule(1, PriceRuleAction::DiscountPercent, '10')]);

        expect($result->finalPrice)->toBe(90_000)
            ->and($result->displayOldPrice)->toBeNull();
    });

    it('ignores show_as_discount on a rule that did not apply', function (): void {
        $rule = pcRule(1, PriceRuleAction::DiscountPercent, '10', ['showAsDiscount' => true, 'scope' => PriceRuleScope::Brands, 'brands' => ['другой']]);

        expect(pcCalc(pcInput(), [$rule])->displayOldPrice)->toBeNull();
    });

    it('does not show an old price that is not above the rounded final price', function (): void {
        $config = new PricingConfig(RoundingStep::Hundred, RoundingDirection::Up);
        $rule = [pcRule(1, PriceRuleAction::DiscountFixed, '0.50', ['showAsDiscount' => true])];

        // 1000 - 0.5 = 999.50 -> rounded up to 1000 = price before discounts: not greater, no old price.
        expect(pcCalc(pcInput(100_000), $rule, $config)->displayOldPrice)->toBeNull();
    });
});

describe('floor', function (): void {
    it('never lets the price go below zero', function (): void {
        $result = pcCalc(pcInput(10_000), [pcRule(1, PriceRuleAction::DiscountFixed, '500')]);

        expect($result->finalPrice)->toBe(0)
            ->and($result->explanation->toLines())->toContain('Цена не ниже нуля → 0'."\u{00A0}₽");
    });

    it('lets later discounts work on a negative intermediate price and clamps once at the end', function (): void {
        $rules = [
            pcRule(1, PriceRuleAction::DiscountFixed, '200', ['priority' => 2]),
            pcRule(2, PriceRuleAction::MarkupFixed, '0', ['priority' => 0]),
            pcRule(3, PriceRuleAction::DiscountPercent, '10', ['priority' => 1]),
        ];

        expect(pcCalc(pcInput(10_000), $rules)->finalPrice)->toBe(0);
    });

    it('keeps a zero base price at zero', function (): void {
        expect(pcCalc(pcInput(0), [pcRule(1, PriceRuleAction::MarkupPercent, '20')])->finalPrice)->toBe(0);
    });
});

describe('percent arithmetic', function (): void {
    it('rounds percentages to kopecks half up', function (): void {
        // 999.99 * 1.15 = 1149.9885 -> 1149.99
        expect(pcCalc(pcInput(99_999), [pcRule(1, PriceRuleAction::MarkupPercent, '15')])->finalPrice)->toBe(114_999);
        // 0.05 * 10 % = 0.005 RUB = 0.5 kopeck -> 1 kopeck (half up)
        expect(pcCalc(pcInput(5), [pcRule(1, PriceRuleAction::MarkupPercent, '10')])->finalPrice)->toBe(6);
        // 0.04 * 10 % = 0.4 kopeck -> no change
        expect(pcCalc(pcInput(4), [pcRule(1, PriceRuleAction::MarkupPercent, '10')])->finalPrice)->toBe(4);
    });

    it('rounds the result of every step to kopecks', function (): void {
        $rules = [
            pcRule(1, PriceRuleAction::MarkupPercent, '33.3333', ['priority' => 2]),
            pcRule(2, PriceRuleAction::DiscountPercent, '12.5'),
        ];

        // 100.01 -> +33.3333 % = 33.3363... => 33.34 -> 133.35 ; -12.5 % of 133.35 = 16.66875 => 16.67 -> 116.68
        expect(pcCalc(pcInput(10_001), $rules)->finalPrice)->toBe(11_668);
    });

    it('applies cost markup with fractional percentages', function (): void {
        $result = pcCalc(pcInput(1, ['costPrice' => 33_333]), [pcRule(1, PriceRuleAction::CostMarkupPercent, '12.5')]);

        // 333.33 * 1.125 = 374.99625 -> 375.00
        expect($result->finalPrice)->toBe(37_500);
    });
});

it('never exceeds the largest decimal(12,2) amount, whatever the rule value', function (): void {
    $max = 999_999_999_999;

    $markup = pcCalc(pcInput(900_000_000_000), [pcRule(1, PriceRuleAction::MarkupPercent, '99999999')]);
    $fixed = pcCalc(pcInput($max), [pcRule(1, PriceRuleAction::MarkupFixed, '99999999.99')]);
    $rounded = pcCalc(pcInput($max - 1), [pcRule(1, PriceRuleAction::MarkupFixed, '0.01')], new PricingConfig(RoundingStep::Hundred, RoundingDirection::Up));

    expect($markup->finalPrice)->toBe($max)
        ->and($fixed->finalPrice)->toBe($max)
        ->and($rounded->finalPrice)->toBeLessThanOrEqual($max);
});
