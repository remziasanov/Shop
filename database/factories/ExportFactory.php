<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Export;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ExportType::Catalog,
            'status' => ExportStatus::Queued,
            'params' => [],
            'rows' => 0,
        ];
    }

    public function status(ExportStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
