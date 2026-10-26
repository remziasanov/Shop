<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Pages;

use App\Filament\Resources\PriceRules\PriceRuleResource;
use App\Filament\Resources\PriceRules\Support\MapsPriceRuleFormData;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPriceRule extends ViewRecord
{
    use MapsPriceRuleFormData;

    protected static string $resource = PriceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
