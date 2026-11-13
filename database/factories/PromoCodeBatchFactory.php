<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PromoBatchStatus;
use App\Models\PromoCodeBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromoCodeBatch>
 */
class PromoCodeBatchFactory extends Factory
{
    protected $model = PromoCodeBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Партия '.$this->faker->unique()->numerify('###'),
            'prefix' => 'GIFT-',
            'count' => 10,
            'code_length' => 8,
            'template' => ['type' => 'percent', 'value' => '10.00'],
            'generated' => 0,
            'status' => PromoBatchStatus::Queued,
        ];
    }
}
