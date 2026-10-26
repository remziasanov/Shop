<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderSequence>
 */
class OrderSequenceFactory extends Factory
{
    protected $model = OrderSequence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['type' => 'order', 'last_number' => 0];
    }
}
