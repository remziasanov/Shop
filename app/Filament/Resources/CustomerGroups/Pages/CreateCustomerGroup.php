<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerGroups\Pages;

use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Filament\Resources\CustomerGroups\Schemas\CustomerGroupForm;
use App\Models\CustomerGroup;
use App\Services\Access\CustomerGroupService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomerGroup extends CreateRecord
{
    protected static string $resource = CustomerGroupResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->form->fill(CustomerGroupForm::defaultState());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        [$attributes, $categoryIds] = CustomerGroupForm::split($data);

        return app(CustomerGroupService::class)->save(new CustomerGroup, $attributes, $categoryIds);
    }
}
