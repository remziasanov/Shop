<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Exceptions\StaffAccessException;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\RoleService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RoleResource::duplicateAction(),
            DeleteAction::make()->using(fn (Role $record): bool => RoleResource::deleteRecord($record)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->getRecord();

        return $data + RoleForm::stateFor($role);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Role $record */
        $actor = auth()->user();

        try {
            return app(RoleService::class)->update(
                $record,
                array_intersect_key($data, array_flip(['code', 'name', 'description'])),
                // The Administrator role stores no permissions (RB-02): the matrix is not submitted for it.
                $record->isAdministrator() ? null : RoleForm::permissionsFrom($data),
                $actor instanceof User ? $actor : abort(403),
            );
        } catch (StaffAccessException $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }
    }
}
