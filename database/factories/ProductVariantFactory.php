<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Variants\VariantService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Variants for tests. The combination hash is random unless {@see withValues()} is used; `withValues()` also writes the
 * `product_variant_values` rows and the axes of the card (`product_options`).
 *
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->withVariants(),
            'sku' => strtoupper($this->faker->unique()->bothify('VR-######')),
            'barcode' => null,
            'combo_hash' => sha1($this->faker->unique()->uuid()),
            'base_price' => null,
            'cost_price' => null,
            'compare_at_price' => null,
            'fixed_price' => null,
            'stock_qty' => 10,
            'reserved_qty' => 0,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (): array => ['product_id' => $product->id]);
    }

    /**
     * Option values of the variant, one per axis; the card's axes follow the order of the first variant.
     */
    public function withValues(OptionValue ...$values): static
    {
        return $this->state(fn (): array => ['combo_hash' => VariantService::comboHash(array_map(fn (OptionValue $v): int => $v->id, $values))])
            ->afterCreating(function (ProductVariant $variant) use ($values): void {
                foreach ($values as $position => $value) {
                    DB::table('product_variant_values')->insert([
                        'variant_id' => $variant->id,
                        'option_type_id' => $value->option_type_id,
                        'option_value_id' => $value->id,
                    ]);

                    DB::table('product_options')->insertOrIgnore([
                        'product_id' => $variant->product_id,
                        'option_type_id' => $value->option_type_id,
                        'sort_order' => $position,
                    ]);
                }
            });
    }

    public function stock(?int $qty): static
    {
        return $this->state(fn (): array => ['stock_qty' => $qty]);
    }

    public function priced(string $basePrice): static
    {
        return $this->state(fn (): array => ['base_price' => $basePrice]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
