<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRulePhase;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * The price calculation of TZ 6.8 (PR-01) as a pure function: no database, no facades, no clock.
 * Rules, the time and the rounding configuration are passed in; money is integer kopecks (D-024).
 *
 * Notes on the details the pseudo-code leaves open:
 *  - a cost markup rule of a product without a cost price is skipped: it is neither applied nor
 *    does it stop the phase, and it does not trigger rounding;
 *  - "show as discount" counts only for applied discount rules;
 *  - the fixed price short-circuits everything, including rounding.
 */
final class PriceCalculator
{
    public function __construct(private readonly Rounding $rounding = new Rounding) {}

    /**
     * @param  list<PriceRuleData>  $rules  candidate rules (matching and period are checked here)
     */
    public function calculate(PriceInput $input, array $rules, CarbonInterface $now, PricingConfig $config): PriceResult
    {
        if ($input->fixedPrice !== null) {
            $final = $input->fixedPrice;
            $old = $input->compareAtPrice !== null && $input->compareAtPrice > $final ? $input->compareAtPrice : null;

            return new PriceResult($final, $old, [], new PriceExplanation([PriceStep::fixed($final)]));
        }

        $price = $input->basePrice;
        $steps = [PriceStep::base($price)];
        $applied = [];
        $priceBeforeDiscounts = $price;
        $discountShown = false;

        $active = array_values(array_filter(
            $rules,
            fn (PriceRuleData $rule): bool => $rule->isInEffect($now) && $rule->matches($input),
        ));

        foreach ([PriceRulePhase::Markup, PriceRulePhase::Discount] as $phase) {
            $phaseRules = array_values(array_filter($active, fn (PriceRuleData $rule): bool => $rule->action->phase() === $phase));
            usort($phaseRules, fn (PriceRuleData $a, PriceRuleData $b): int => [$b->priority, $a->id] <=> [$a->priority, $b->id]);

            foreach ($phaseRules as $rule) {
                if ($rule->action === PriceRuleAction::CostMarkupPercent && $input->costPrice === null) {
                    $steps[] = PriceStep::skipped($rule, 'no_cost_price', $price);

                    continue;
                }

                $before = $price;
                $price = $this->apply($rule, $price, $input->costPrice);
                $applied[] = $rule->id;
                $steps[] = PriceStep::rule($rule, $before, $price);

                if ($phase === PriceRulePhase::Discount && $rule->showAsDiscount) {
                    $discountShown = true;
                }

                if ($rule->stopProcessing) {
                    break;
                }
            }

            if ($phase === PriceRulePhase::Markup) {
                $priceBeforeDiscounts = $price;
            }
        }

        if ($price < 0) {
            $steps[] = PriceStep::floor($price, 0);
            $price = 0;
        }

        if (($applied !== [] || $config->scope === RoundingScope::All) && $config->step !== RoundingStep::None) {
            $rounded = $this->rounding->apply($price, $config->step, $config->direction, $config->psychological);
            $plain = $this->rounding->apply($price, $config->step, $config->direction, false);
            $steps[] = PriceStep::rounding($price, $rounded, $config->step, $config->direction, $rounded !== $plain);
            $price = $rounded;
        }

        $old = max($input->compareAtPrice ?? 0, $discountShown ? $priceBeforeDiscounts : 0);

        return new PriceResult($price, $old > $price ? $old : null, $applied, new PriceExplanation($steps));
    }

    private function apply(PriceRuleData $rule, int $price, ?int $costPrice): int
    {
        return match ($rule->action) {
            PriceRuleAction::MarkupPercent => $price + $this->percentOf($price, $rule->value),
            PriceRuleAction::DiscountPercent => $price - $this->percentOf($price, $rule->value),
            PriceRuleAction::MarkupFixed => $price + Money::fromRubles($rule->value),
            PriceRuleAction::DiscountFixed => $price - Money::fromRubles($rule->value),
            PriceRuleAction::CostMarkupPercent => (int) $costPrice + $this->percentOf((int) $costPrice, $rule->value),
        };
    }

    /**
     * round_half_up(kopecks * percent / 100) with bcmath, half away from zero for negative amounts.
     */
    private function percentOf(int $kopecks, string $percent): int
    {
        $exact = bcdiv(bcmul((string) abs($kopecks), $percent, 4), '100', 6);
        $rounded = (int) bcadd($exact, '0.5', 0);

        return $kopecks < 0 ? -$rounded : $rounded;
    }
}
