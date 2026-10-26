<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    protected $model = Import::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ImportStatus::Uploaded,
            'mode' => ImportMode::CreateUpdate,
            'original_name' => 'catalog.csv',
            'file_path' => 'imports/0/source.csv',
            'sheet' => null,
            'media_source' => MediaSource::None,
            'chunk_size' => 200,
        ];
    }

    public function status(ImportStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function mode(ImportMode $mode): static
    {
        return $this->state(fn (): array => ['mode' => $mode]);
    }
}
