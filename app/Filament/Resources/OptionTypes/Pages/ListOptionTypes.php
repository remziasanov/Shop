<?php

declare(strict_types=1);

namespace App\Filament\Resources\OptionTypes\Pages;

use App\Filament\Resources\OptionTypes\OptionTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOptionTypes extends ListRecords
{
    protected static string $resource = OptionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
