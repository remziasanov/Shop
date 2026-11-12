<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OptionType;
use App\Models\OptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionValue>
 */
class OptionValueFactory extends Factory
{
    protected $model = OptionValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'option_type_id' => OptionType::factory(),
            'value' => 'Значение '.$this->faker->unique()->bothify('??##'),
            'slug' => '',
            'hex' => null,
            'image_path' => null,
            'sort_order' => 0,
        ];
    }

    public function forType(OptionType $type): static
    {
        return $this->state(fn (): array => ['option_type_id' => $type->id]);
    }

    public function hex(string $hex): static
    {
        return $this->state(fn (): array => ['hex' => $hex]);
    }
}
