<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\WholesaleApplications\Tables\WholesaleApplicationsTable;
use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use App\Models\WholesaleApplication;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab «Заявки на опт» of a customer (CA-20): the applications of this customer, newest first, each opening its card in
 * the «Заявки на опт» resource. Needs `Customer::wholesaleApplications()`. Read only.
 */
class WholesaleApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'wholesaleApplications';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('wholesale.relation.title');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns(WholesaleApplicationsTable::columns(withCustomer: false))
            ->recordActions([
                ViewAction::make()
                    ->url(fn (WholesaleApplication $record): string => WholesaleApplicationResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
