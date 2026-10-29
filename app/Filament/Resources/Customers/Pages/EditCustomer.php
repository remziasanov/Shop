<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAdminService;
use App\Support\PhoneNormalizer;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Correction of the contact fields of a customer (CA-20). Saved through the admin service: the permission is checked
 * and the change is written to the journal (CA-21).
 *
 * @property Customer $record
 */
class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->record]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User && $record instanceof Customer, 403);

        $phone = is_string($data['phone'] ?? null) && trim($data['phone']) !== '' ? $data['phone'] : null;

        return app(CustomerAdminService::class)->update($record, [
            'name' => trim((string) ($data['name'] ?? $record->name)),
            // A phone that is a valid Russian number is stored in the canonical form, anything else as typed.
            'phone' => $phone === null ? null : (PhoneNormalizer::normalize($phone) ?? $phone),
        ], $actor);
    }
}
