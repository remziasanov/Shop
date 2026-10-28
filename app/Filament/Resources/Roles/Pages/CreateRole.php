<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Exceptions\StaffAccessException;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\User;
use App\Services\Users\RoleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        try {
            return app(RoleService::class)->create(
                ['code' => (string) $data['code'], 'name' => (string) $data['name'], 'description' => $data['description'] ?? null],
                RoleForm::permissionsFrom($data),
                $actor instanceof User ? $actor : abort(403),
            );
        } catch (StaffAccessException $e) {
            throw ValidationException::withMessages(['data.code' => $e->getMessage()]);
        }
    }
}
