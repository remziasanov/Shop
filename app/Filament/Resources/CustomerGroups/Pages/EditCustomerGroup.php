<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerGroups\Pages;

use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Filament\Resources\CustomerGroups\Schemas\CustomerGroupForm;
use App\Models\CustomerGroup;
use App\Services\Access\CustomerGroupService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomerGroup extends EditRecord
{
    protected static string $resource = CustomerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
