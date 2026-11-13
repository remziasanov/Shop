<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoRedemption>
 */
class PromoRedemptionFactory extends Factory
{
    protected $model = PromoRedemption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promo_code_id' => PromoCode::factory(),
            'order_id' => Order::factory(),
            'customer_id' => null,
            'customer_hash' => null,
            'discount_amount' => '100.00',
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => ['released_at' => now()]);
    }
}
