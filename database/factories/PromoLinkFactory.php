<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromoLinkTargetType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A link to the catalog by default.
 *
 * @extends Factory<PromoLink>
 */
class PromoLinkFactory extends Factory
{
    protected $model = PromoLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'link-'.$this->faker->unique()->numerify('#####'),
            'name' => 'Тестовая ссылка',
            'target_type' => PromoLinkTargetType::Url,
            'target_url' => '/catalog',
            'is_active' => true,
        ];
    }

    public function toProduct(Product $product): static
    {
        return $this->state(fn (): array => ['target_type' => PromoLinkTargetType::Product, 'target_id' => $product->id, 'target_url' => null]);
    }

    public function toVariant(ProductVariant $variant): static
    {
        return $this->state(fn (): array => [
            'target_type' => PromoLinkTargetType::Variant,
            'target_id' => $variant->product_id,
            'variant_id' => $variant->id,
            'target_url' => null,
        ]);
    }

    public function toCategory(Category $category): static
    {
        return $this->state(fn (): array => ['target_type' => PromoLinkTargetType::Category, 'target_id' => $category->id, 'target_url' => null]);
    }

    public function toUrl(string $url): static
    {
        return $this->state(fn (): array => ['target_type' => PromoLinkTargetType::Url, 'target_url' => $url]);
    }

    public function toCatalogFilter(string $pathWithQuery): static
    {
        return $this->state(fn (): array => ['target_type' => PromoLinkTargetType::CatalogFilter, 'target_url' => $pathWithQuery]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
