<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A list value by default; use number() / boolean() for the other types. Rows created here bypass AttributeService:
 * call AttributeSyncService afterwards when the JSON cache matters.
 *
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    protected $model = ProductAttributeValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'attribute_id' => fn (): int => Attribute::factory()->create()->id,
            'attribute_value_id' => fn (array $attributes): int => AttributeValue::factory()->create(['attribute_id' => $attributes['attribute_id']])->id,
            'value_number' => null,
            'value_bool' => null,
        ];
    }

    public function number(string $value): static
    {
        return $this->state(fn (): array => [
            'attribute_id' => Attribute::factory()->number('Вт'),
            'attribute_value_id' => null,
            'value_number' => $value,
        ]);
    }

    public function boolean(bool $value): static
    {
        return $this->state(fn (): array => [
            'attribute_id' => Attribute::factory()->boolean(),
            'attribute_value_id' => null,
            'value_bool' => $value,
        ]);
    }
}
