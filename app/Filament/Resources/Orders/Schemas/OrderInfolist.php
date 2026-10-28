<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Request card (OR-03): customer, items with price snapshot, delivery, totals, comments, status history, consent.
 */
final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin_orders.view.section_main'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('number')->label(__('admin_orders.view.number'))->weight('bold')->copyable(),
                        TextEntry::make('type')
                            ->label(__('admin_orders.view.type'))
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (OrderType|string $state): string => ($state instanceof OrderType ? $state : OrderType::from($state))->label()),
                        TextEntry::make('status')
                            ->label(__('admin_orders.view.status'))
                            ->badge()
                            ->color(fn (OrderStatus|string $state): string => ($state instanceof OrderStatus ? $state : OrderStatus::from($state))->color())
                            ->formatStateUsing(fn (OrderStatus|string $state, Order $record): string => ($state instanceof OrderStatus ? $state : OrderStatus::from($state))->label($record->type)),
                        TextEntry::make('created_at')->label(__('admin_orders.view.created_at'))->dateTime('d.m.Y H:i'),
                        TextEntry::make('reserved_until')
                            ->label(__('admin_orders.view.reserved_until'))
                            ->dateTime('d.m.Y H:i')
                            ->color(fn (Order $record): ?string => OrdersTable::isUrgent($record) ? 'danger' : null)
                            ->visible(fn (Order $record): bool => $record->type === OrderType::Reservation),
                        TextEntry::make('desired_date')
                            ->label(__('admin_orders.view.desired_date'))
                            ->date('d.m.Y')
                            ->placeholder('—')
                            ->visible(fn (Order $record): bool => $record->type === OrderType::Reservation),
                    ]),

                Section::make(__('admin_orders.view.section_customer'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('customer_name')->label(__('admin_orders.view.name')),
                        TextEntry::make('customer_phone')
                            ->label(__('admin_orders.view.phone'))
                            ->url(fn (Order $record): string => 'tel:'.$record->customer_phone),
                        TextEntry::make('whatsapp')
                            ->label(__('admin_orders.view.whatsapp'))
                            ->state(fn (Order $record): string => '+'.self::digits($record))
                            ->url(fn (Order $record): string => 'https://wa.me/'.self::digits($record))
                            ->openUrlInNewTab(),
                        TextEntry::make('telegram')
                            ->label(__('admin_orders.view.telegram'))
                            ->state(fn (Order $record): string => '+'.self::digits($record))
                            ->url(fn (Order $record): string => 'https://t.me/+'.self::digits($record))
                            ->openUrlInNewTab(),
                        TextEntry::make('customer_email')
                            ->label(__('admin_orders.view.email'))
                            ->placeholder('—')
                            ->url(fn (Order $record): ?string => $record->customer_email === null ? null : 'mailto:'.$record->customer_email),
                        TextEntry::make('consent_at')
                            ->label(__('admin_orders.view.consent'))
                            ->state(fn (Order $record): string => __('admin_orders.view.consent_value', [
                                'time' => $record->consent_at->format('d.m.Y H:i'),
                                'ip' => $record->consent_ip,
                            ])),
                        // AG-11: the level the customer confirmed at checkout; hidden for orders without restricted products.
                        TextEntry::make('age_confirmed_level')
                            ->hiddenLabel()
                            ->badge()
                            ->color('danger')
                            ->columnSpanFull()
                            ->state(fn (Order $record): string => __('age_gate.admin.order_confirmed', [
                                'level' => $record->age_confirmed_level?->badge() ?? '',
                            ]))
                            ->visible(fn (Order $record): bool => $record->age_confirmed_level !== null),
                    ]),

                Section::make(__('admin_orders.view.section_items'))
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('admin_orders.view.sku')),
                                TableColumn::make(__('admin_orders.view.item_name')),
                                TableColumn::make(__('admin_orders.view.price'))->alignEnd(),
                                TableColumn::make(__('admin_orders.view.qty'))->alignEnd(),
                                TableColumn::make(__('admin_orders.view.line_total'))->alignEnd(),
                            ])
                            ->schema([
                                TextEntry::make('sku'),
                                TextEntry::make('name')
                                    ->url(fn (OrderItem $record): ?string => $record->product !== null && ! $record->product->trashed()
                                        ? ProductResource::getUrl('edit', ['record' => $record->product])
                                        : null),
                                TextEntry::make('price')->formatStateUsing(fn (?string $state): string => self::money($state, __('admin_orders.view.price_on_request'))),
                                TextEntry::make('qty')->formatStateUsing(fn (int $state, OrderItem $record): string => $state.' '.$record->unit),
                                TextEntry::make('total')->formatStateUsing(fn (?string $state): string => self::money($state, '—')),
                            ]),
                    ]),

                Section::make(__('admin_orders.view.section_delivery'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('delivery_method')->label(__('admin_orders.view.delivery_method'))->placeholder('—'),
                        TextEntry::make('delivery_address')->label(__('admin_orders.view.delivery_address'))->placeholder('—'),
                        TextEntry::make('payment_method')->label(__('admin_orders.view.payment_method'))->placeholder('—'),
                        TextEntry::make('items_total')->label(__('admin_orders.view.items_total'))->formatStateUsing(fn (?string $state): string => self::money($state, '—')),
                        TextEntry::make('delivery_price')->label(__('admin_orders.view.delivery_price'))->formatStateUsing(fn (?string $state): string => self::money($state, '—')),
                        TextEntry::make('total')->label(__('admin_orders.view.total'))->weight('bold')->formatStateUsing(fn (?string $state): string => self::money($state, '—')),
                    ]),

                Section::make(__('admin_orders.view.section_comments'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('customer_comment')->label(__('admin_orders.view.customer_comment'))->placeholder(__('admin_orders.view.no_comment')),
                        TextEntry::make('manager_comment')->label(__('admin_orders.view.manager_comment'))->placeholder(__('admin_orders.view.no_comment')),
                    ]),

                Section::make(__('admin_orders.view.section_history'))
                    ->schema([
                        RepeatableEntry::make('statusHistory')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('admin_orders.view.history_when')),
                                TableColumn::make(__('admin_orders.view.history_change')),
                                TableColumn::make(__('admin_orders.view.history_who')),
                                TableColumn::make(__('admin_orders.view.history_comment')),
                            ])
                            ->schema([
                                TextEntry::make('created_at')->dateTime('d.m.Y H:i'),
                                TextEntry::make('change')->state(fn (OrderStatusHistory $record): string => self::change($record)),
                                TextEntry::make('actor')->state(fn (OrderStatusHistory $record): string => $record->user->name ?? __('admin_orders.view.actor_system')),
                                TextEntry::make('comment')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    private static function digits(Order $order): string
    {
        return preg_replace('/\D+/', '', $order->customer_phone) ?? '';
    }

    private static function money(?string $value, string $empty): string
    {
        return $value === null ? $empty : Money::format(Money::fromRubles($value));
    }

    private static function change(OrderStatusHistory $history): string
    {
        $type = $history->order->type;
        $to = $history->to_status->label($type);

        return $history->from_status === null
            ? $to.' ('.__('admin_orders.view.created').')'
            : $history->from_status->label($type).' → '.$to;
    }
}
