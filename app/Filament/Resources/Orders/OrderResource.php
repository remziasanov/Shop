<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Services\Orders\NewRequestsCounter;
use App\Services\Orders\OrderQuery;
use App\Support\Money;
use BackedEnum;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Customer requests: orders, reservations and inquiries (OR-01…OR-07, AD-04). Requests are created by customers
 * on the storefront, so the admin only lists, views and processes them.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.sales');
    }

    public static function getModelLabel(): string
    {
        return __('admin_orders.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_orders.plural');
    }

    /**
     * Counter of new requests on the menu item (AD-04), cached for 30 seconds.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(NewRequestsCounter::class)->total();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin_orders.navigation.badge_tooltip');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'customer_phone'];
    }

    /**
     * Global search by number and phone digits (AD-04); the phone is matched regardless of formatting.
     *
     * @return Collection<int, GlobalSearchResult>
     */
    public static function getGlobalSearchResults(string $search): Collection
    {
        $query = Order::query();
        OrderQuery::search($query, $search, numberAndPhoneOnly: true);

        return $query
            ->orderByDesc('orders.id')
            ->limit(static::getGlobalSearchResultsLimit())
            ->get()
            ->map(fn (Order $order): GlobalSearchResult => new GlobalSearchResult(
                title: static::getGlobalSearchResultTitle($order),
                url: static::getUrl('view', ['record' => $order]),
                details: static::getGlobalSearchResultDetails($order),
            ));
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Order $record */
        return [
            __('admin_orders.global_search.type') => $record->type->label(),
            __('admin_orders.global_search.status') => $record->status->label($record->type),
            __('admin_orders.global_search.total') => $record->total === null ? '—' : Money::format(Money::fromRubles($record->total)),
        ];
    }
}
