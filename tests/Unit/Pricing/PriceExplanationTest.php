<?php

declare(strict_types=1);

use App\Enums\PriceRuleAction;
use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Services\Pricing\PriceExplanation;
use App\Services\Pricing\PriceStep;
use App\Services\Pricing\PriceStepType;
use App\Services\Pricing\PricingConfig;
use App\Support\Money;

it('describes the steps in Russian', function (): void {
    $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Up, false, RoundingScope::Changed);
    $rules = [
        pcRule(1, PriceRuleAction::MarkupPercent, '20.0000', ['name' => 'Электроника']),
        pcRule(2, PriceRuleAction::DiscountPercent, '10.0000', ['name' => 'Чёрная пятница', 'priority' => 5]),
    ];

    $result = pcCalc(pcInput(100_000), $rules, $config);

    expect($result->explanation->toLines())->toBe([
        'База '.Money::format(100_000),
        'Наценка “Электроника +20'."\u{00A0}".'%” → '.Money::format(120_000),
        'Акция “Чёрная пятница −10'."\u{00A0}".'%” → '.Money::format(108_000),
        'Округление до '.Money::format(1_000).' вверх → '.Money::format(108_000),
    ]);
    expect($result->explanation->steps[1]->type)->toBe(PriceStepType::Rule)
        ->and($result->explanation->steps[1]->delta)->toBe(20_000)
        ->and($result->explanation->steps[2]->delta)->toBe(-12_000)
        ->and($result->explanation->steps[2]->priceAfter)->toBe(108_000);
});

it('formats fixed and fractional values', function (): void {
    $rules = [
        pcRule(1, PriceRuleAction::MarkupFixed, '150.0000', ['name' => 'Доставка']),
        pcRule(2, PriceRuleAction::DiscountPercent, '12.5000', ['name' => 'Акция']),
    ];

    $lines = pcCalc(pcInput(100_000), $rules)->explanation->toLines();

    expect($lines[1])->toStartWith('Наценка “Доставка +'.Money::format(15_000).'”')
        ->and($lines[2])->toStartWith('Акция “Акция −12,5'."\u{00A0}".'%”');
});

it('describes psychological rounding, the fixed price and the floor', function (): void {
    $config = new PricingConfig(RoundingStep::Ten, RoundingDirection::Nearest, true, RoundingScope::All);

    expect(pcCalc(pcInput(100_100), [], $config)->explanation->toLines()[1])
        ->toBe('Округление до '.Money::format(1_000).' к ближайшему, психологическая цена −1 ₽ → '.Money::format(99_900))
        ->and(pcCalc(pcInput(100_000, ['fixedPrice' => 85_000]))->explanation->toLines())
        ->toBe(['Фиксированная цена '.Money::format(85_000).', правила не применяются']);
});

it('keeps steps in order and exposes them as value objects', function (): void {
    $explanation = new PriceExplanation([PriceStep::base(100), PriceStep::floor(-5, 0)]);

    expect($explanation->steps)->toHaveCount(2)
        ->and($explanation->toLines())->toHaveCount(2);
});
