<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeBatches\Pages;

use App\Filament\Resources\PromoCodeBatches\PromoCodeBatchResource;
use App\Filament\Resources\PromoCodeBatches\Support\PromoBatchActions;
use Filament\Resources\Pages\ListRecords;

class ListPromoCodeBatches extends ListRecords
{
    protected static string $resource = PromoCodeBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PromoBatchActions::generate(),
        ];
    }
}
