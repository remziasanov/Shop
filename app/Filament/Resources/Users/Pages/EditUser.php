<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\StaffAccessException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Users\StaffAccountService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (User $record): bool => UserResource::deleteRecord($record)),
        ];
    }

    /**
     * Rights, escalation, self-change and last-administrator rules live in the service, which re-checks them
     * under row locks so two admins demoting each other at once cannot leave no active admin (RB-07, RB-08).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        try {
            /** @var User $record */
            return app(StaffAccountService::class)->update($record, $data, $actor instanceof User ? $actor : abort(403));
        } catch (StaffAccessException $e) {
            throw ValidationException::withMessages(['data.role_id' => $e->getMessage()]);
        }
    }
}
