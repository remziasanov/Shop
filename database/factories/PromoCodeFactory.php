<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromoScope;
use App\Enums\PromoTargetType;
use App\Enums\PromoType;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A 10 % code for everything, available to «Гость» and «Розница» (PM-04 default).
 *
 * @extends Factory<PromoCode>
 */
class PromoCodeFactory extends Factory
{
    protected $model = PromoCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST'.strtoupper($this->faker->unique()->bothify('??##??')),
            'name' => 'Тестовый промокод',
            'type' => PromoType::Percent,
            'value' => '10.00',
            'scope' => PromoScope::All,
            'include_subcategories' => true,
            'only_registered' => false,
            'applies_to_discounted' => false,
            'stackable_with_promotions' => false,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (PromoCode $code): void {
            if (! $code->groups()->exists()) {
                $code->groups()->sync(PromoCode::defaultGroupIds());
            }
        });
    }

    public function percent(float|int|string $value): static
    {
        return $this->state(fn (): array => ['type' => PromoType::Percent, 'value' => number_format((float) $value, 2, '.', '')]);
    }

    public function fixed(string $rubles): static
    {
        return $this->state(fn (): array => ['type' => PromoType::Fixed, 'value' => $rubles]);
    }

    public function freeDelivery(): static
    {
        return $this->state(fn (): array => ['type' => PromoType::FreeDelivery, 'value' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function oneTime(): static
    {
        return $this->state(fn (): array => ['usage_limit' => 1, 'per_customer_limit' => 1]);
    }

    /**
     * @param  list<Category>  $categories
     */
    public function forCategories(array $categories, bool $withSubcategories = true): static
    {
        return $this->state(fn (): array => ['scope' => PromoScope::Categories, 'include_subcategories' => $withSubcategories])
            ->afterCreating(function (PromoCode $code) use ($categories): void {
                foreach ($categories as $category) {
                    $code->targets()->create(['target_type' => PromoTargetType::Category, 'target_id' => $category->id]);
                }
            });
    }

    /**
     * @param  list<Product>  $products
     */
    public function forProducts(array $products): static
    {
        return $this->state(fn (): array => ['scope' => PromoScope::Products])
            ->afterCreating(function (PromoCode $code) use ($products): void {
                foreach ($products as $product) {
                    $code->targets()->create(['target_type' => PromoTargetType::Product, 'target_id' => $product->id]);
                }
            });
    }

    /**
     * @param  list<string>  $brands
     */
    public function forBrands(array $brands): static
    {
        return $this->state(fn (): array => ['scope' => PromoScope::Brands])
            ->afterCreating(function (PromoCode $code) use ($brands): void {
                foreach ($brands as $brand) {
                    $code->targets()->create(['target_type' => PromoTargetType::Brand, 'target_value' => $brand]);
                }
            });
    }

    /**
     * Replaces the default groups.
     */
    public function forGroups(CustomerGroup ...$groups): static
    {
        return $this->afterCreating(function (PromoCode $code) use ($groups): void {
            $code->groups()->sync(array_map(fn (CustomerGroup $group): int => $group->id, $groups));
        });
    }
}
