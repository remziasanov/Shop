<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderQuery;
use App\Services\Orders\OrderService;
use App\Support\Money;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Request list (OR-01, OR-02). All conditions go through {@see OrderQuery}, the same code the export uses.
 */
final class OrdersTable
{
    /** A reservation with fewer hours left is shown in red (OR-01). */
    public const URGENT_HOURS = 6;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin_orders.table.number'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    // MR-12: «связан с Z-00045» next to the number.
                    ->description(fn (Order $record): ?string => $record->relatedOrder === null ? null : __('admin_orders.table.related', ['number' => $record->relatedOrder->number])),
                TextColumn::make('type')
                    ->label(__('admin_orders.table.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (OrderType|string $state): string => ($state instanceof OrderType ? $state : OrderType::from($state))->label()),
                // TM-14: where the request came from; can be hidden with the column switch.
                TextColumn::make('channel')
                    ->label(__('pwa.admin.channel'))
                    ->badge()
                    ->color(fn (OrderChannel|string $state): string => ($state instanceof OrderChannel ? $state : OrderChannel::from($state))->color())
                    ->formatStateUsing(fn (OrderChannel|string $state): string => ($state instanceof OrderChannel ? $state : OrderChannel::from($state))->label())
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin_orders.table.status'))
                    ->badge()
                    ->color(fn (OrderStatus|string $state): string => ($state instanceof OrderStatus ? $state : OrderStatus::from($state))->color())
                    ->formatStateUsing(fn (OrderStatus|string $state, Order $record): string => ($state instanceof OrderStatus ? $state : OrderStatus::from($state))->label($record->type)),
                TextColumn::make('customer_name')
                    ->label(__('admin_orders.table.customer')),
                TextColumn::make('customer_phone')
                    ->label(__('admin_orders.table.phone'))
                    ->url(fn (Order $record): string => 'tel:'.$record->customer_phone)
                    ->color('gray'),
                TextColumn::make('total')
                    ->label(__('admin_orders.table.total'))
                    ->sortable()
                    ->alignEnd()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Money::format(Money::fromRubles($state))),
                TextColumn::make('items_count')
                    ->label(__('admin_orders.table.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label(__('admin_orders.table.created_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('reserved_until')
                    ->label(__('admin_orders.table.reserved_until'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Order $record): ?string => self::isUrgent($record) ? 'danger' : null),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('relatedOrder:id,number,type'))
            ->searchUsing(fn (Builder $query, string $search) => OrderQuery::search($query, $search))
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin_orders.filters.type'))
                    ->options(OrderType::options())
                    ->query(fn (Builder $query, array $data): Builder => OrderQuery::apply($query, ['type' => $data['value'] ?? null])),
                SelectFilter::make('status')
                    ->label(__('admin_orders.filters.status'))
                    ->options(OrderStatus::options())
                    ->query(fn (Builder $query, array $data): Builder => OrderQuery::apply($query, ['status' => $data['value'] ?? null])),
                SelectFilter::make('channel')
                    ->label(__('pwa.admin.channel'))
                    ->options(OrderChannel::options())
                    ->query(fn (Builder $query, array $data): Builder => OrderQuery::apply($query, ['channel' => $data['value'] ?? null])),
                Filter::make('period')
                    ->label(__('admin_orders.filters.period'))
                    ->schema([
                        DatePicker::make('created_from')->label(__('admin_orders.filters.created_from')),
                        DatePicker::make('created_until')->label(__('admin_orders.filters.created_until')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => OrderQuery::apply($query, [
                        'created_from' => $data['created_from'] ?? null,
                        'created_until' => $data['created_until'] ?? null,
                    ]))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['created_from'] ?? null)) {
                            $indicators[] = __('admin_orders.filters.indicator_from', ['date' => Carbon::parse($data['created_from'])->format('d.m.Y')]);
                        }

                        if (filled($data['created_until'] ?? null)) {
                            $indicators[] = __('admin_orders.filters.indicator_until', ['date' => Carbon::parse($data['created_until'])->format('d.m.Y')]);
                        }

                        return $indicators;
                    }),
                Filter::make('reserved_via_cart')
                    ->label(__('admin_orders.filters.reserved_via_cart'))
                    ->toggle()
                    ->query(fn (Builder $query, array $data): Builder => OrderQuery::apply($query, ['reserved_via_cart' => (bool) ($data['isActive'] ?? false)])),
                SelectFilter::make('delivery_method')
                    ->label(__('admin_orders.filters.delivery'))
                    ->options(fn (): array => Order::query()
                        ->whereNotNull('delivery_method')
                        ->distinct()
                        ->orderBy('delivery_method')
                        ->pluck('delivery_method', 'delivery_method')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => OrderQuery::apply($query, ['delivery_method' => $data['value'] ?? null])),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // D-051: deletion goes through the service so active requests give their stock back.
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, iterable $records): void {
                            $actor = Auth::user();
                            $isFirst = true;

                            foreach ($records as $record) {
                                try {
                                    app(OrderService::class)->delete($record, $actor instanceof User ? $actor : null);
                                } catch (Throwable $e) {
                                    $action->reportBulkProcessingFailure();

                                    if ($isFirst) {
                                        report($e);
                                        $isFirst = false;
                                    }
                                }
                            }
                        }),
                ]),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('orders.created_at')->orderByDesc('orders.id'))
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('admin_orders.table.empty_heading'));
    }

    public static function isUrgent(Order $order): bool
    {
        return $order->reserved_until !== null
            && in_array($order->status, [OrderStatus::New, OrderStatus::Reserved], true)
            && $order->reserved_until->lt(now()->addHours(self::URGENT_HOURS));
    }
}
