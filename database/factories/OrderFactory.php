<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fake requests; names are generic faker data and phones are fictional +7 numbers.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'number' => 'Z-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'type' => OrderType::Order,
            'status' => OrderStatus::New,
            'customer_name' => $this->faker->name(),
            'customer_phone' => '+7900'.$this->faker->numerify('#######'),
            'customer_email' => null,
            'delivery_method' => null,
            'delivery_address' => null,
            'delivery_price' => '0.00',
            'payment_method' => null,
            'items_total' => null,
            'total' => null,
            'customer_comment' => null,
            'manager_comment' => null,
            'reserved_until' => null,
            'desired_date' => null,
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
            'user_agent' => 'Pest',
        ];
    }

    public function order(): static
    {
        return $this->state(fn (): array => ['type' => OrderType::Order, 'status' => OrderStatus::New, 'reserved_until' => null]);
    }

    public function reservation(): static
    {
        return $this->state(fn (): array => [
            'type' => OrderType::Reservation,
            'status' => OrderStatus::New,
            'number' => 'R-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'reserved_until' => now()->addHours(48),
        ]);
    }

    public function inquiry(): static
    {
        return $this->state(fn (): array => [
            'type' => OrderType::Inquiry,
            'status' => OrderStatus::New,
            'number' => 'Q-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'reserved_until' => null,
        ]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function reservedUntil(\DateTimeInterface $until): static
    {
        return $this->state(fn (): array => ['reserved_until' => $until]);
    }

    /**
     * Adds generic snapshot items and fills the totals.
     */
    public function withItems(int $count = 1): static
    {
        return $this->afterCreating(function (Order $order) use ($count): void {
            $items = OrderItem::factory()->count($count)->create(['order_id' => $order->id]);
            $sum = $items->sum(fn (OrderItem $item): int => Money::fromRubles((string) $item->total));
            $order->forceFill([
                'items_total' => Money::toRubles($sum),
                'total' => Money::toRubles($sum + Money::fromRubles($order->delivery_price)),
            ])->save();
        });
    }
}
