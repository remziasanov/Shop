<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Users\AdminGuard;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();

        /** @var User $record */
        $record = $this->getRecord();

        app(AdminGuard::class)->assertCanChange($record, $actor instanceof User ? $actor : null, $data);

        return $data;
    }

    /**
     * Re-check under row locks so two admins demoting each other at once cannot leave no active admin.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            User::query()->where('role', 'admin')->where('is_active', true)->lockForUpdate()->get(['id']);

            $actor = auth()->user();
            /** @var User $record */
            app(AdminGuard::class)->assertCanChange($record, $actor instanceof User ? $actor : null, $data);

            return parent::handleRecordUpdate($record, $data);
        });
    }
}
