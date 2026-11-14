<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
{
    protected $model = TelegramUpdate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'update_id' => fake()->unique()->numberBetween(1, 4_000_000_000),
            'type' => 'message',
            'received_at' => now(),
        ];
    }
}
