<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeBatches\Pages;

use App\Filament\Resources\PromoCodeBatches\PromoCodeBatchResource;
use App\Filament\Resources\PromoCodeBatches\Support\PromoBatchActions;
use Filament\Resources\Pages\ViewRecord;

class ViewPromoCodeBatch extends ViewRecord
{
    protected static string $resource = PromoCodeBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PromoBatchActions::applyTemplate(),
            PromoBatchActions::export(),
        ];
    }
}
