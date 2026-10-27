<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Pages;

use App\Enums\MenuLocation;
use App\Filament\Resources\MenuItems\MenuItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMenuItems extends ListRecords
{
    protected static string $resource = MenuItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /**
     * One tab per menu, so drag and drop reorders the items of a single menu.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach (MenuLocation::cases() as $location) {
            $tabs[$location->value] = Tab::make($location->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('location', $location->value));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return MenuLocation::Header->value;
    }
}
