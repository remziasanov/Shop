<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductGroupPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductGroupPrice>
 */
class ProductGroupPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'variant_key' => 0,
            'customer_group_id' => CustomerGroup::factory(),
            'final_price' => '900.00',
            'display_old_price' => null,
            'computed_at' => now(),
        ];
    }
}
