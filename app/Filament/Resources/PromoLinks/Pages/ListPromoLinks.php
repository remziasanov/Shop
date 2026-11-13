<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Pages;

use App\Filament\Resources\PromoLinks\PromoLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromoLinks extends ListRecords
{
    protected static string $resource = PromoLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
