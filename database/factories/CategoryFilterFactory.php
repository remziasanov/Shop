<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FilterSource;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\CategoryFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryFilter>
 */
class CategoryFilterFactory extends Factory
{
    protected $model = CategoryFilter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'source' => FilterSource::Attribute,
            'attribute_id' => Attribute::factory(),
            'option_type_id' => null,
            'sort_order' => 0,
            'is_expanded' => false,
        ];
    }
}
