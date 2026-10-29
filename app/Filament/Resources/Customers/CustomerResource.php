<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Customers\RelationManagers\WholesaleApplicationsRelationManager;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use App\Services\Customers\CustomerAdminService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Покупатели» under Продажи (CA-20, CA-21): list with search and filters, a card with the requisites and the tabs
 * «Заказы», «Адреса» (and «Заявки на опт»). Customers register themselves, so the admin does not create or delete
 * them; the actions of the card go through {@see CustomerAdminService}. There is no sign-in
 * as a customer.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('customers.resource.plural');
    }

    public static function getModelLabel(): string
    {
        return __('customers.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customers.resource.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Only the contact fields can be corrected by staff; the rest has its own actions.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('customers.view.name'))
                ->required()
                ->maxLength(150),
            TextInput::make('phone')
                ->label(__('customers.view.phone'))
                ->maxLength(32),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
            AddressesRelationManager::class,
            WholesaleApplicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
