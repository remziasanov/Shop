<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Pages;

use App\Filament\Resources\PromoLinks\PromoLinkResource;
use App\Filament\Resources\PromoLinks\Support\PromoLinkActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPromoLink extends ViewRecord
{
    protected static string $resource = PromoLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            PromoLinkActions::copy(),
            PromoLinkActions::copyTelegram(),
            PromoLinkActions::check(),
            PromoLinkActions::preview(),
        ];
    }
}
