<?php

declare(strict_types=1);

namespace App\Filament\Resources\WholesaleApplications\Pages;

use App\Filament\Resources\WholesaleApplications\Actions\WholesaleDecisionActions;
use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use App\Models\WholesaleApplication;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property WholesaleApplication $record
 */
class ViewWholesaleApplication extends ViewRecord
{
    protected static string $resource = WholesaleApplicationResource::class;

    public function getTitle(): string
    {
        return __('wholesale.resource.singular').' #'.$this->record->id;
    }

    protected function getHeaderActions(): array
    {
        return [
            WholesaleDecisionActions::approve(),
            WholesaleDecisionActions::reject(),
            WholesaleDecisionActions::returnToNew(),
        ];
    }
}
