<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Filament\Resources\Imports\ImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('import_ui.resource.new'))
                ->icon(Heroicon::OutlinedArrowUpTray),
        ];
    }

    public function getSubheading(): ?string
    {
        return __('import_ui.resource.retention', ['days' => config('shop.import_retention_days')]);
    }
}
