<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceRuleTargetType;
use App\Models\PriceRule;
use App\Models\PriceRuleTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceRuleTarget>
 */
class PriceRuleTargetFactory extends Factory
{
    protected $model = PriceRuleTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'price_rule_id' => PriceRule::factory(),
            'target_type' => PriceRuleTargetType::Brand,
            'target_id' => null,
            'target_value' => 'Север',
        ];
    }
}
