<?php

declare(strict_types=1);

use App\Enums\PriceRuleAction;
use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Services\Pricing\GroupPriceContext;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PriceInput;
use App\Services\Pricing\PriceResult;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PricingConfig;
use Carbon\CarbonImmutable;

/**
 * Group context of the calculator (GP-04, GP-05, GP-19, GP-21). Money in kopecks: 100_000 = 1 000 RUB.
 */
function gcInput(int $base = 100_000, array $overrides = []): PriceInput
{
    return new PriceInput(...array_merge([
        'productId' => 1, 'categoryId' => 10, 'brand' => 'Север', 'isFeatured' => false, 'isNew' => false,
        'basePrice' => $base, 'categoryPathIds' => [10],
    ], $overrides));
}

function gcRule(int $id, PriceRuleAction $action, string $value, array $groupIds = [], array $overrides = []): PriceRuleData
{
    return new PriceRuleData(...array_merge(['id' => $id, 'name' => 'Rule '.$id, 'action' => $action, 'value' => $value, 'groupIds' => $groupIds], $overrides));
}

/**
 * @param  list<PriceRuleData>  $rules
 */
function gcCalc(PriceInput $input, array $rules = [], ?GroupPriceContext $group = null, ?PricingConfig $config = null): PriceResult
{
    return (new PriceCalculator)->calculate($input, $rules, CarbonImmutable::parse('2026-06-01 12:00:00'), $config ?? new PricingConfig, $group);
}

it('ignores rules bound to groups for the retail price (GP-04)', function (): void {
    $rules = [gcRule(1, PriceRuleAction::DiscountPercent, '10', [7])];

    expect(gcCalc(gcInput(), $rules)->finalPrice)->toBe(100_000)
        ->and(gcCalc(gcInput(), $rules)->appliedRuleIds)->toBe([]);
});

it('applies a rule bound to the group only to that group', function (): void {
    $rules = [gcRule(1, PriceRuleAction::DiscountPercent, '10', [7])];

    expect(gcCalc(gcInput(), $rules, new GroupPriceContext(7))->finalPrice)->toBe(90_000)
        ->and(gcCalc(gcInput(), $rules, new GroupPriceContext(8))->finalPrice)->toBe(100_000);
});

it('applies a rule for everyone to the retail price and to every group', function (): void {
    $rules = [gcRule(1, PriceRuleAction::MarkupPercent, '20')];

    expect(gcCalc(gcInput(), $rules)->finalPrice)->toBe(120_000)
        ->and(gcCalc(gcInput(), $rules, new GroupPriceContext(7))->finalPrice)->toBe(120_000);
});

it('applies the group discount after the rules', function (): void {
    $rules = [gcRule(1, PriceRuleAction::MarkupPercent, '20')];
    $result = gcCalc(gcInput(), $rules, new GroupPriceContext(7, '15'));

    // 1 000 -> 1 200 -> 1 020.
    expect($result->finalPrice)->toBe(102_000)
        ->and($result->explanation->toLines()[2])->toContain('Скидка группы 15 %');
});

it('keeps the retail price for a group without discount and group rules', function (): void {
    expect(gcCalc(gcInput(), [], new GroupPriceContext(7))->finalPrice)->toBe(100_000);
});

it('rounds the discounted price half up to the kopeck', function (): void {
    // 105 kopecks * 90 % = 94.5 -> 95 (not 94 as rounding the discount would give).
    expect(gcCalc(gcInput(105), [], new GroupPriceContext(7, '10'))->finalPrice)->toBe(95)
        ->and(gcCalc(gcInput(333), [], new GroupPriceContext(7, '33.33'))->finalPrice)->toBe(222);
});

it('lets the fixed price disable the rules but not the group discount (GP-05)', function (): void {
    $rules = [gcRule(1, PriceRuleAction::MarkupPercent, '50')];
    $input = gcInput(100_000, ['fixedPrice' => 80_000]);

    expect(gcCalc($input, $rules)->finalPrice)->toBe(80_000)
        ->and(gcCalc($input, $rules, new GroupPriceContext(7))->finalPrice)->toBe(80_000)
        ->and(gcCalc($input, $rules, new GroupPriceContext(7, '10'))->finalPrice)->toBe(72_000);
});

it('shows the compare-at price of a discounted fixed price', function (): void {
    $input = gcInput(100_000, ['fixedPrice' => 80_000, 'compareAtPrice' => 90_000]);

    expect(gcCalc($input, [], new GroupPriceContext(7, '10'))->displayOldPrice)->toBe(90_000);
});

it('applies the rounding step to derived group prices when GP-19 is on', function (): void {
    $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up);

    // 1 000 -> 873,6 (12.64 % off) -> 880.
    $on = gcCalc(gcInput(), [], new GroupPriceContext(7, '12.64', true), $config);
    $off = gcCalc(gcInput(), [], new GroupPriceContext(7, '12.64', false), $config);

    expect($on->finalPrice)->toBe(88_000)->and($off->finalPrice)->toBe(87_360);
});

it('rounds a discounted fixed price only when GP-19 is on', function (): void {
    $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up);
    $input = gcInput(100_000, ['fixedPrice' => 99_900]);

    expect(gcCalc($input, [], new GroupPriceContext(7, '5', true), $config)->finalPrice)->toBe(95_000)
        ->and(gcCalc($input, [], new GroupPriceContext(7, '5', false), $config)->finalPrice)->toBe(94_905)
        ->and(gcCalc($input, [], new GroupPriceContext(7, '0', true), $config)->finalPrice)->toBe(99_900);
});

it('does not touch a group price that equals the retail calculation, whatever GP-19 says', function (): void {
    $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up, false, RoundingScope::All);
    $retail = gcCalc(gcInput(100_005), [], null, $config);

    expect(gcCalc(gcInput(100_005), [], new GroupPriceContext(7, '0', false), $config)->finalPrice)->toBe($retail->finalPrice);
});

it('behaves exactly like v1 without a group context', function (): void {
    $rules = [gcRule(1, PriceRuleAction::DiscountPercent, '10', [], ['showAsDiscount' => true])];
    $result = gcCalc(gcInput(), $rules);

    expect($result->finalPrice)->toBe(90_000)->and($result->displayOldPrice)->toBe(100_000);
});
