<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerGroups\Pages;

use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Filament\Resources\CustomerGroups\Schemas\CustomerGroupForm;
use App\Models\CustomerGroup;
use App\Services\Access\CustomerGroupService;
use App\Services\Pricing\PriceListBuilder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditCustomerGroup extends EditRecord
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CG-17: queue a rebuild of the wholesale price list of this group.
            Action::make('refresh_price_list')
                ->label(__('admin_group_prices.price_list.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (CustomerGroup $record): bool => app(PriceListBuilder::class)->isEligible($record))
                ->modalDescription(function (CustomerGroup $record): string {
                    $builtAt = app(PriceListBuilder::class)->builtAt($record);

                    return $builtAt === null
                        ? __('admin_group_prices.price_list.never_built')
                        : __('admin_group_prices.price_list.built_at', ['time' => $builtAt->timezone(config('app.timezone'))->format('d.m.Y H:i')]);
                })
                ->requiresConfirmation()
                ->action(function (CustomerGroup $record): void {
                    app(PriceListBuilder::class)->queue($record);

                    Notification::make()->success()->title(__('admin_group_prices.price_list.queued', ['count' => 1]))->send();
                }),
            CustomerGroupResource::previewAction(),
            CustomerGroupResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var CustomerGroup $group */
        $group = $this->getRecord();

        return $data + CustomerGroupForm::stateFor($group) + ['visible_sections' => $group->visible_sections];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CustomerGroup $record */
        [$attributes, $categoryIds] = CustomerGroupForm::split($data);

        return app(CustomerGroupService::class)->save($record, $attributes, $categoryIds);
    }
}
