<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab «Адреса» of a customer card (CA-20): the address book of the customer. Read only.
 */
class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('customers.relations.addresses');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')->label(__('customers.relations.address_label'))->weight('bold'),
                TextColumn::make('recipient_name')->label(__('customers.relations.recipient')),
                TextColumn::make('phone')->label(__('customers.relations.phone')),
                TextColumn::make('address')->label(__('customers.relations.address'))->wrap(),
                IconColumn::make('is_default')->label(__('customers.relations.default'))->boolean(),
            ])
            ->paginated(false);
    }
}
