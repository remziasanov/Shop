<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttributeType;
use App\Models\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name, '-', 'ru').'-'.$this->faker->unique()->numerify('###'),
            'type' => AttributeType::List,
            'unit' => null,
            'is_filterable' => true,
            'index_single_value' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function number(?string $unit = null): static
    {
        return $this->state(fn (): array => ['type' => AttributeType::Number, 'unit' => $unit]);
    }

    public function boolean(): static
    {
        return $this->state(fn (): array => ['type' => AttributeType::Boolean, 'unit' => null]);
    }

    public function notFilterable(): static
    {
        return $this->state(fn (): array => ['is_filterable' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
