<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'product_id' => Product::factory(),
            'qty' => 1,
            'price_at_add' => '100.00',
        ];
    }

    /**
     * Item for the product at its current final price.
     */
    public function forProduct(Product $product, int $qty = 1): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product->id,
            'qty' => $qty,
            'price_at_add' => $product->final_price,
        ]);
    }
}
