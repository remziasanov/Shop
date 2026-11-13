<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Resources\PromoCodes\Tables\PromoCodesTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPromoCode extends ViewRecord
{
    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            PromoCodesTable::duplicateAction(),
            PromoCodesTable::deactivateAction(),
        ];
    }
}
