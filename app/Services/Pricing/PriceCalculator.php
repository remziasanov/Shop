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
 *  - with a group context (GP-04, GP-05) rules bound to other groups are ignored, the group discount follows the rules
 *    (also on a fixed price), and the rounding step applies to a price that the group changed (a discount or a group
 *    rule) only when GP-19 allows. Without a group context rules bound to groups never apply (they do not touch retail).
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

        $discounted = $group !== null && $group->hasDiscount();

        if ($group !== null && $discounted) {
            $after = $this->scale($price, $group->discountPercent);
            $steps[] = PriceStep::groupDiscount($price, $after, $group->discountPercent);
            $price = $after;
        }

        // A price that the group changed is rounded only when GP-19 allows; otherwise it is the retail calculation.
        $groupSpecific = $discounted || $this->usesGroupRule($active, $applied);
        $roundingAllowed = $group === null || ! $groupSpecific || $group->applyRounding;
        $changed = $applied !== [] || $discounted;

        if ($roundingAllowed && ($changed || $config->scope === RoundingScope::All) && $config->step !== RoundingStep::None) {
            $rounded = $this->rounding->apply($price, $config->step, $config->direction, $config->psychological);
            $plain = $this->rounding->apply($price, $config->step, $config->direction, false);
            $steps[] = PriceStep::rounding($price, $rounded, $config->step, $config->direction, $rounded !== $plain);
            $price = min($rounded, RublesAmount::DEFAULT_MAX_KOPECKS);
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
                $rounded = $this->rounding->apply($final, $config->step, $config->direction, $config->psychological);
                $plain = $this->rounding->apply($final, $config->step, $config->direction, false);
                $steps[] = PriceStep::rounding($final, $rounded, $config->step, $config->direction, $rounded !== $plain);
                $final = min($rounded, RublesAmount::DEFAULT_MAX_KOPECKS);
            }
        }

        $old = $input->compareAtPrice !== null && $input->compareAtPrice > $final ? $input->compareAtPrice : null;

        return new PriceResult($final, $old, [], new PriceExplanation($steps));
    }

    /**
     * Whether an applied rule is bound to groups (the price then differs from the retail calculation).
     *
     * @param  list<PriceRuleData>  $active
     * @param  list<int>  $applied  ids of the applied rules
     */
    private function usesGroupRule(array $active, array $applied): bool
    {
        foreach ($active as $rule) {
            if ($rule->groupIds !== [] && in_array($rule->id, $applied, true)) {
                return true;
            }
        }

        return false;
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
