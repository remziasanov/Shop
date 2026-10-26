<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalesMode;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * Slug, depth and path are filled by the observer.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'description' => '<p>'.$this->faker->sentence(12).'</p>',
            'is_active' => true,
            'sales_mode' => null,
            'sort_order' => 0,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    /**
     * A child of the given category (or of a new one).
     */
    public function childOf(Category|int|null $parent = null): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent instanceof Category ? $parent->id : ($parent ?? Category::factory()),
        ]);
    }

    /**
     * Child categories created after this one, with this category as their parent.
     */
    public function withChildren(int $count = 2): static
    {
        return $this->has(static::new()->count($count), 'children');
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function salesMode(SalesMode $mode): static
    {
        return $this->state(fn (): array => ['sales_mode' => $mode]);
    }
}
