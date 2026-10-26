<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceChangeSource;
use App\Models\PriceHistory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceHistory>
 */
class PriceHistoryFactory extends Factory
{
    protected $model = PriceHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $old = $this->faker->numberBetween(100, 5000);

        return [
            'product_id' => Product::factory(),
            'field' => 'base_price',
            'old_value' => number_format($old, 2, '.', ''),
            'new_value' => number_format($old + $this->faker->numberBetween(1, 500), 2, '.', ''),
            'source' => PriceChangeSource::Manual,
            'user_id' => null,
            'import_id' => null,
            'created_at' => now(),
        ];
    }

    public function source(PriceChangeSource $source): static
    {
        return $this->state(fn (): array => ['source' => $source]);
    }
}
