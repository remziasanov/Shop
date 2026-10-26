<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = $this->faker->numberBetween(1, 3);
        $price = $this->faker->numberBetween(100, 20000) * 100;

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'sku' => strtoupper($this->faker->unique()->bothify('??-######')),
            'name' => 'Товар '.$this->faker->bothify('??-###'),
            'unit' => 'шт',
            'price' => Money::toRubles($price),
            'qty' => $qty,
            'total' => Money::toRubles($price * $qty),
            'stock_applied_qty' => 0,
        ];
    }

    /**
     * Snapshot of an existing product.
     */
    public function forProduct(Product $product, int $qty = 1): static
    {
        return $this->state(function () use ($product, $qty): array {
            $price = Money::fromRubles($product->final_price);

            return [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'unit' => $product->unit,
                'price' => Money::toRubles($price),
                'qty' => $qty,
                'total' => Money::toRubles($price * $qty),
            ];
        });
    }
}
