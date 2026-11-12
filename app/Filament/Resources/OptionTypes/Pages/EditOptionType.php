<?php

declare(strict_types=1);

namespace App\Filament\Resources\OptionTypes\Pages;

use App\Filament\Resources\OptionTypes\OptionTypeResource;
use App\Models\OptionType;
use App\Services\Variants\OptionService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOptionType extends EditRecord
{
    protected static string $resource = OptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [OptionTypeResource::deleteAction()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(OptionService::class)->updateType($record instanceof OptionType ? $record : OptionType::query()->findOrFail($record->getKey()), $data);
    }
}
