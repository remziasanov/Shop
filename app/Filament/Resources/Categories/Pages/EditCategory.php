<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\Concerns\MapsTreeErrors;
use App\Filament\Resources\Categories\Pages\Concerns\SavesCategoryImage;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    use MapsTreeErrors;
    use SavesCategoryImage;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CategoryResource::openOnSiteAction(),
            CategoryResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->guardTree(fn (): Model => $this->saveWithImage($data, fn (array $clean): Model => parent::handleRecordUpdate($record, $clean)));
    }
}
