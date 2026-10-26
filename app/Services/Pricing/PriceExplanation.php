<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;

/**
 * Ordered, human readable breakdown of a price calculation (PR-03).
 */
final readonly class PriceExplanation
{
    /**
     * @param  list<PriceStep>  $steps
     */
    public function __construct(public array $steps) {}

    /**
     * Russian lines, e.g. «База 1 000 ₽», «Наценка “Электроника +20 %” → 1 200 ₽».
     *
     * @return list<string>
     */
    public function toLines(): array
    {
        return array_map(fn (PriceStep $step): string => $this->line($step), $this->steps);
    }

    private function line(PriceStep $step): string
    {
        $price = Money::format($step->priceAfter);

        switch ($step->type) {
            case PriceStepType::Base:
                return __('pricing.explanation.base', ['price' => $price]);

            case PriceStepType::Fixed:
                return __('pricing.explanation.fixed', ['price' => $price]);

            case PriceStepType::Floor:
                return __('pricing.explanation.floor', ['price' => $price]);

            case PriceStepType::Skipped:
                return __('pricing.explanation.skipped', [
                    'name' => $step->label,
                    'reason' => __('pricing.explanation.skipped_reasons.'.$step->reason),
                ]);

            case PriceStepType::Rounding:
                return __($step->psychological ? 'pricing.explanation.rounding_psychological' : 'pricing.explanation.rounding', [
                    'step' => Money::format((int) $step->roundingStep?->value * 100),
                    'direction' => __('pricing.rounding_directions.'.$step->roundingDirection?->value),
                    'price' => $price,
                ]);

            case PriceStepType::Rule:
                return __($step->action?->isDiscount() ? 'pricing.explanation.discount' : 'pricing.explanation.markup', [
                    'label' => $step->label.' '.$this->signedValue($step),
                    'price' => $price,
                ]);
        }
    }

    /**
     * "+20 %", "−10 %", "+100 ₽", "−50,5 %".
     */
    private function signedValue(PriceStep $step): string
    {
        $sign = $step->action?->isDiscount() ? '−' : '+';
        $value = (string) $step->value;

        if ($step->action?->isPercent()) {
            $number = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

            return $sign.str_replace('.', ',', $number).Money::CURRENCY_SEPARATOR.'%';
        }

        return $sign.Money::format(Money::fromRubles($value));
    }
}
