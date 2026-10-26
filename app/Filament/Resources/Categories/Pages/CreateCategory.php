<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\Concerns\MapsTreeErrors;
use App\Filament\Resources\Categories\Pages\Concerns\SavesCategoryImage;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCategory extends CreateRecord
{
    use MapsTreeErrors;
    use SavesCategoryImage;

    protected static string $resource = CategoryResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return $this->guardTree(fn (): Model => $this->saveWithImage($data, fn (array $clean): Model => parent::handleRecordCreation($clean)));
    }
}
