<?php

declare(strict_types=1);

use App\Enums\RoundingDirection;
use App\Enums\RoundingStep;
use App\Services\Pricing\Rounding;

it('returns the price unchanged for the "none" step', function (): void {
    expect((new Rounding)->apply(12_345, RoundingStep::None, RoundingDirection::Up, true))->toBe(12_345);
});

it('rounds to the step in the given direction', function (RoundingStep $step, RoundingDirection $direction, int $price, int $expected): void {
    expect((new Rounding)->apply($price, $step, $direction))->toBe($expected);
})->with([
    'one up' => [RoundingStep::One, RoundingDirection::Up, 1_001, 1_100],
    'one nearest half up' => [RoundingStep::One, RoundingDirection::Nearest, 1_050, 1_100],
    'one nearest below half' => [RoundingStep::One, RoundingDirection::Nearest, 1_049, 1_000],
    'one down' => [RoundingStep::One, RoundingDirection::Down, 1_099, 1_000],
    'ten up exact' => [RoundingStep::Ten, RoundingDirection::Up, 2_000, 2_000],
    'ten up' => [RoundingStep::Ten, RoundingDirection::Up, 2_001, 3_000],
    'fifty nearest half up' => [RoundingStep::Fifty, RoundingDirection::Nearest, 12_500, 15_000],
    'hundred down' => [RoundingStep::Hundred, RoundingDirection::Down, 19_999, 10_000],
    'zero' => [RoundingStep::Hundred, RoundingDirection::Up, 0, 0],
]);

it('subtracts 1 RUB psychologically only from 10 RUB steps and only for a positive result', function (): void {
    $rounding = new Rounding;

    expect($rounding->apply(10_100, RoundingStep::Ten, RoundingDirection::Up, true))->toBe(10_900)
        ->and($rounding->apply(10_100, RoundingStep::One, RoundingDirection::Up, true))->toBe(10_100)
        ->and($rounding->apply(0, RoundingStep::Ten, RoundingDirection::Up, true))->toBe(0)
        ->and($rounding->apply(500, RoundingStep::Ten, RoundingDirection::Down, true))->toBe(0);
});
