<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPriceTier>
 */
class ProductPriceTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'customer_group_id' => CustomerGroup::factory(),
            'min_qty' => 10,
            'price' => '900.00',
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->id, 'variant_id' => null]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (): array => ['product_id' => $variant->product_id, 'variant_id' => $variant->id]);
    }

    public function forGroup(CustomerGroup $group): static
    {
        return $this->state(fn (): array => ['customer_group_id' => $group->id]);
    }

    public function from(int $minQty, string $price): static
    {
        return $this->state(fn (): array => ['min_qty' => $minQty, 'price' => $price]);
    }
}
