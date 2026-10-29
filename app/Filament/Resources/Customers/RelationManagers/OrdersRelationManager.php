<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tab «Заказы» of a customer card (CA-20): the requests of all types placed under the account, newest first.
 * Read only; each row opens the request card.
 */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('customers.relations.orders');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')
                    ->label(__('customers.relations.number'))
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label(__('customers.relations.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (OrderType|string $state): string => ($state instanceof OrderType ? $state : OrderType::from($state))->label()),
                TextColumn::make('status')
                    ->label(__('customers.relations.status'))
                    ->badge()
                    ->color(fn (OrderStatus|string $state): string => ($state instanceof OrderStatus ? $state : OrderStatus::from($state))->color())
                    ->formatStateUsing(fn (OrderStatus|string $state, Order $record): string => ($state instanceof OrderStatus ? $state : OrderStatus::from($state))->label($record->type)),
                TextColumn::make('total')
                    ->label(__('customers.relations.total'))
                    ->alignEnd()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : Money::format(Money::fromRubles($state))),
                TextColumn::make('created_at')
                    ->label(__('customers.relations.created_at'))
                    ->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
