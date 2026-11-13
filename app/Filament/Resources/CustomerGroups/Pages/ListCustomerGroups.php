<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerGroups\Pages;

use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Models\CustomerGroup;
use App\Services\Pricing\PriceListBuilder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ListCustomerGroups extends ListRecords
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CG-17: the same build the nightly `shop:build-price-lists` queues, now for every group with the section on.
            Action::make('refresh_price_lists')
                ->label(__('admin_group_prices.price_list.refresh_all'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => Gate::allows('create', CustomerGroup::class))
                ->requiresConfirmation()
                ->modalDescription(__('admin_group_prices.price_list.refresh_all_hint'))
                ->action(function (): void {
                    $count = app(PriceListBuilder::class)->queueAll();

                    $count > 0
                        ? Notification::make()->success()->title(__('admin_group_prices.price_list.queued', ['count' => $count]))->send()
                        : Notification::make()->warning()->title(__('admin_group_prices.price_list.none'))->send();
                }),
            CreateAction::make(),
        ];
    }
}
