<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\StorefrontSection;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerGroup>
 */
class CustomerGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'group-'.fake()->unique()->bothify('????##'),
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 100,
            'visible_sections' => StorefrontSection::defaults(),
            'category_access_mode' => CategoryAccessMode::All,
            'price_display' => PriceDisplayMode::Show,
            'allowed_sales_modes' => null,
            'min_order_amount' => null,
            'allowed_delivery_codes' => null,
            'allowed_payment_codes' => null,
            'stock_display' => GroupStockDisplay::Inherit,
        ];
    }

    /**
     * @param  list<StorefrontSection>  $sections
     */
    public function sections(array $sections): static
    {
        return $this->state(fn (): array => ['visible_sections' => array_map(fn (StorefrontSection $s): string => $s->value, $sections)]);
    }

    public function withoutSection(StorefrontSection $section): static
    {
        return $this->state(fn (): array => ['visible_sections' => array_values(array_diff(StorefrontSection::defaults(), [$section->value]))]);
    }

    public function priceDisplay(PriceDisplayMode $mode): static
    {
        return $this->state(fn (): array => ['price_display' => $mode]);
    }

    /**
     * @param  list<string>|null  $modes  cart / reservation / inquiry
     */
    public function salesModes(?array $modes): static
    {
        return $this->state(fn (): array => ['allowed_sales_modes' => $modes]);
    }

    /**
     * Category access with the selected categories attached after creation.
     *
     * @param  list<Category>  $categories
     */
    public function categoryAccess(CategoryAccessMode $mode, array $categories = []): static
    {
        return $this->state(fn (): array => ['category_access_mode' => $mode])
            ->afterCreating(function (CustomerGroup $group) use ($categories): void {
                $group->categories()->sync(array_map(fn (Category $category): int => $category->id, $categories));
                CatalogVersion::bump();
            });
    }
}
