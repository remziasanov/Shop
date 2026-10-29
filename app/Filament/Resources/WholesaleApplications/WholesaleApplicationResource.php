<?php

declare(strict_types=1);

namespace App\Filament\Resources\WholesaleApplications;

use App\Filament\Resources\WholesaleApplications\Pages\ListWholesaleApplications;
use App\Filament\Resources\WholesaleApplications\Pages\ViewWholesaleApplication;
use App\Filament\Resources\WholesaleApplications\Schemas\WholesaleApplicationInfolist;
use App\Filament\Resources\WholesaleApplications\Tables\WholesaleApplicationsTable;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * «Заявки на опт» under Продажи (CG-22, CA-22): list with the number of new applications on the menu item, the card of
 * an application and the decision. The decision itself is {@see WholesaleApplicationService}; abilities come from the
 * `wholesale_applications.*` permissions (WholesaleApplicationPolicy).
 */
class WholesaleApplicationResource extends Resource
{
    protected static ?string $model = WholesaleApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 30;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('wholesale.resource.plural');
    }

    public static function getModelLabel(): string
    {
        return __('wholesale.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('wholesale.resource.plural');
    }

    /**
     * CG-21: the number of applications waiting for a decision (cached for 30 seconds).
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(WholesaleApplicationService::class)->pendingCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('wholesale.resource.badge_tooltip');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer.group', 'decidedBy', 'decidedGroup']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WholesaleApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WholesaleApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWholesaleApplications::route('/'),
            'view' => ViewWholesaleApplication::route('/{record}'),
        ];
    }
}
