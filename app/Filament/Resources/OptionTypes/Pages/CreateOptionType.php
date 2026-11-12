<?php

declare(strict_types=1);

namespace App\Filament\Resources\OptionTypes\Pages;

use App\Filament\Resources\OptionTypes\OptionTypeResource;
use App\Services\Variants\OptionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOptionType extends CreateRecord
{
    protected static string $resource = OptionTypeResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OptionService::class)->createType($data);
    }
}
