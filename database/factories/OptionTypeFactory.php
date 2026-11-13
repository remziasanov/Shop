<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OptionDisplayType;
use App\Models\OptionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionType>
 */
class OptionTypeFactory extends Factory
{
    protected $model = OptionType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Опция '.$this->faker->unique()->bothify('??##'),
            'slug' => '',
            'display_type' => OptionDisplayType::Select,
            'is_filterable' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function color(): static
    {
        return $this->state(fn (): array => ['display_type' => OptionDisplayType::Color]);
    }

    public function button(): static
    {
        return $this->state(fn (): array => ['display_type' => OptionDisplayType::Button]);
    }

    public function filterable(): static
    {
        return $this->state(fn (): array => ['is_filterable' => true]);
    }
}
