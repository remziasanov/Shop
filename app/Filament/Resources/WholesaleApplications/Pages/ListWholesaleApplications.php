<?php

declare(strict_types=1);

namespace App\Filament\Resources\WholesaleApplications\Pages;

use App\Enums\WholesaleApplicationStatus;
use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWholesaleApplications extends ListRecords
{
    protected static string $resource = WholesaleApplicationResource::class;

    /**
     * «Новые» (the default), «Одобренные», «Отклонённые» and «Все».
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach (WholesaleApplicationStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status->value));
        }

        $tabs['all'] = Tab::make(__('wholesale.table.tabs.all'));

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return WholesaleApplicationStatus::New->value;
    }
}
