<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => ucfirst($this->faker->unique()->words(3, true)),
            'content' => '<p>'.$this->faker->sentence(12).'</p>',
            'is_active' => true,
            'meta_title' => null,
            'meta_description' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
