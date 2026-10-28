<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\StaffAccessException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Users\StaffAccountService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        try {
            return app(StaffAccountService::class)->create($data, $actor instanceof User ? $actor : abort(403));
        } catch (StaffAccessException $e) {
            throw ValidationException::withMessages(['data.role_id' => $e->getMessage()]);
        }
    }
}
