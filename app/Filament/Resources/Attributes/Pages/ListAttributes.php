<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Pages;

use App\Filament\Resources\Attributes\AttributeResource;
use App\Filament\Resources\Attributes\Support\MigrateFromJsonAction;
use App\Filament\Widgets\AttributeMigrationWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttributes extends ListRecords
{
    protected static string $resource = AttributeResource::class;

    /** Dry-run report of the open «Перенести характеристики из JSON» modal (FL-04, step 1). */
    public ?array $migrationReport = null;

    protected function getHeaderActions(): array
    {
        return [
            MigrateFromJsonAction::make(),
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [AttributeMigrationWidget::class];
    }
}
