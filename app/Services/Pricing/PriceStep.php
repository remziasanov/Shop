<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceRuleAction;
use App\Enums\RoundingDirection;
use App\Enums\RoundingStep;

/**
 * One step of the price explanation. `label` is the rule name for rule/skipped steps, otherwise empty.
 * `delta` is the signed change in kopecks, `priceAfter` the running price in kopecks.
 */
final readonly class PriceStep
{
    public function __construct(
        public PriceStepType $type,
        public string $label,
        public int $delta,
        public int $priceAfter,
        public ?int $ruleId = null,
        public ?PriceRuleAction $action = null,
        public ?string $value = null,
        public ?string $reason = null,
        public ?RoundingStep $roundingStep = null,
        public ?RoundingDirection $roundingDirection = null,
        public bool $psychological = false,
    ) {}

    public static function base(int $price): self
    {
        return new self(PriceStepType::Base, '', 0, $price);
    }

    public static function fixed(int $price): self
    {
        return new self(PriceStepType::Fixed, '', 0, $price);
    }

    public static function rule(PriceRuleData $rule, int $before, int $after): self
    {
        return new self(PriceStepType::Rule, $rule->name, $after - $before, $after, $rule->id, $rule->action, $rule->value);
    }

    public static function skipped(PriceRuleData $rule, string $reason, int $price): self
    {
        return new self(PriceStepType::Skipped, $rule->name, 0, $price, $rule->id, $rule->action, $rule->value, $reason);
    }

    public static function rounding(int $before, int $after, RoundingStep $step, RoundingDirection $direction, bool $psychological): self
    {
        return new self(PriceStepType::Rounding, '', $after - $before, $after, null, null, null, null, $step, $direction, $psychological);
    }

    public static function floor(int $before, int $after): self
    {
        return new self(PriceStepType::Floor, '', $after - $before, $after);
    }
}
