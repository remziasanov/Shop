<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MenuLinkType;
use App\Enums\MenuLocation;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location' => MenuLocation::Header,
            'label' => ucfirst($this->faker->unique()->words(2, true)),
            'link_type' => MenuLinkType::Url,
            'url' => '/catalog',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function footer(): static
    {
        return $this->state(fn (): array => ['location' => MenuLocation::Footer]);
    }

    public function forCategory(Category|int|null $category = null): static
    {
        return $this->state(fn (): array => [
            'link_type' => MenuLinkType::Category,
            'url' => null,
            'category_id' => $category instanceof Category ? $category->id : ($category ?? Category::factory()),
        ]);
    }

    public function forPage(Page|int|null $page = null): static
    {
        return $this->state(fn (): array => [
            'link_type' => MenuLinkType::Page,
            'url' => null,
            'page_id' => $page instanceof Page ? $page->id : ($page ?? Page::factory()),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
