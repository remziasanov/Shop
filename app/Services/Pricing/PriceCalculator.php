<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRulePhase;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Rules\RublesAmount;
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
 *  - the fixed price short-circuits everything, including rounding;
 *  - with a group context (GP-04, GP-05, 21.2 step 3) the normal pipeline runs with the rules for everyone and the
 *    rules bound to the group, rounding included; then the group discount (half up to the kopeck, also on a fixed
 *    price), then the rounding step once more only when GP-19 allows. Without a group context rules bound to groups
 *    never apply (they do not touch retail).
 */
final class PriceCalculator
{
    public function __construct(private readonly Rounding $rounding = new Rounding) {}

    /**
     * @param  list<PriceRuleData>  $rules  candidate rules (matching and period are checked here)
     */
    public function calculate(PriceInput $input, array $rules, CarbonInterface $now, PricingConfig $config, ?GroupPriceContext $group = null): PriceResult
    {
        if ($input->fixedPrice !== null) {
            return $this->fixed($input, $config, $group);
        }

        $price = $input->basePrice;
        $steps = [PriceStep::base($price)];
        $applied = [];
        $priceBeforeDiscounts = $price;
        $discountShown = false;

        $active = array_values(array_filter(
            $rules,
            fn (PriceRuleData $rule): bool => $rule->appliesToGroup($group?->groupId) && $rule->isInEffect($now) && $rule->matches($input),
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
                // Never above what a decimal(12,2) column holds, whatever the rule value.
                $price = min($this->apply($rule, $price, $input->costPrice), RublesAmount::DEFAULT_MAX_KOPECKS);
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

        // The retail pipeline, rounding included, with the rules for everyone and the rules bound to the group (21.2 step 3).
        if (($applied !== [] || $config->scope === RoundingScope::All) && $config->step !== RoundingStep::None) {
            $price = $this->round($price, $config, $steps);
        }

        // Then the group discount, half up to the kopeck, and the rounding step again only when GP-19 allows.
        if ($group !== null && $group->hasDiscount()) {
            $after = $this->scale($price, $group->discountPercent);
            $steps[] = PriceStep::groupDiscount($price, $after, $group->discountPercent);
            $price = $after;

            if ($group->applyRounding && $config->step !== RoundingStep::None) {
                $price = $this->round($price, $config, $steps);
            }
        }

        $old = max($input->compareAtPrice ?? 0, $discountShown ? $priceBeforeDiscounts : 0);

        return new PriceResult($price, $old > $price ? $old : null, $applied, new PriceExplanation($steps));
    }

    /**
     * The fixed price of a product: no rules, no rounding. A group discount still applies to it (GP-05).
     */
    private function fixed(PriceInput $input, PricingConfig $config, ?GroupPriceContext $group): PriceResult
    {
        $final = (int) $input->fixedPrice;
        $steps = [PriceStep::fixed($final)];

        if ($group !== null && $group->hasDiscount()) {
            $discounted = $this->scale($final, $group->discountPercent);
            $steps[] = PriceStep::groupDiscount($final, $discounted, $group->discountPercent);
            $final = $discounted;

            if ($group->applyRounding && $config->step !== RoundingStep::None) {
                $final = $this->round($final, $config, $steps);
            }
        }

        $old = $input->compareAtPrice !== null && $input->compareAtPrice > $final ? $input->compareAtPrice : null;

        return new PriceResult($final, $old, [], new PriceExplanation($steps));
    }

    /**
     * Apply the rounding step of the configuration and record the step.
     *
     * @param  list<PriceStep>  $steps
     */
    private function round(int $price, PricingConfig $config, array &$steps): int
    {
        $rounded = $this->rounding->apply($price, $config->step, $config->direction, $config->psychological);
        $plain = $this->rounding->apply($price, $config->step, $config->direction, false);
        $steps[] = PriceStep::rounding($price, $rounded, $config->step, $config->direction, $rounded !== $plain);

        return min($rounded, RublesAmount::DEFAULT_MAX_KOPECKS);
    }

    /**
     * round_half_up(kopecks * (100 - percent) / 100) with bcmath (GP-05).
     */
    private function scale(int $kopecks, string $discountPercent): int
    {
        $factor = bcsub('100', $discountPercent, 4);
        $exact = bcdiv(bcmul((string) max(0, $kopecks), $factor, 4), '100', 6);

        return (int) bcadd($exact, '0.5', 0);
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
