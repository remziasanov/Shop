<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportIssueLevel;
use App\Models\Import;
use App\Models\ImportIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportIssue>
 */
class ImportIssueFactory extends Factory
{
    protected $model = ImportIssue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_id' => Import::factory(),
            'row_number' => $this->faker->numberBetween(2, 500),
            'column' => 'Цена',
            'level' => ImportIssueLevel::Error,
            'message' => 'Цена должна быть числом не меньше 0.',
            'value' => 'abc',
            'created_at' => now(),
        ];
    }

    public function warning(): static
    {
        return $this->state(fn (): array => ['level' => ImportIssueLevel::Warning]);
    }
}
