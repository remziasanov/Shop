<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Tables;

use App\Enums\PromoLinkTargetType;
use App\Filament\Resources\PromoLinks\PromoLinkResource;
use App\Filament\Resources\PromoLinks\Support\PromoLinkActions;
use App\Filament\Resources\PromoLinks\Support\PromoLinkSummary;
use App\Models\PromoLink;
use App\Services\Promo\PromoLinkSaver;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Promo link list (PM-21, PM-26): name, address, destination, code, clicks, period, active; copy / check / preview actions.
 */
final class PromoLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('promoCode')->withSum('stats as clicks_total', 'clicks'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('promo_admin.links.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('slug')
                    ->label(__('promo_admin.links.fields.slug'))
                    ->state(fn (PromoLink $record): string => '/go/'.$record->slug)
                    ->copyable()
                    ->copyableState(fn (PromoLink $record): string => app(PromoLinkSaver::class)->publicUrl($record->slug))
                    ->searchable(),
                TextColumn::make('target_type')
                    ->label(__('promo_admin.links.table.target'))
                    ->state(fn (PromoLink $record): string => PromoLinkSummary::target($record))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('promoCode.code')
                    ->label(__('promo_admin.links.table.code'))
                    ->placeholder(__('promo_admin.links.table.no_code')),
                TextColumn::make('clicks_total')
                    ->label(__('promo_admin.links.table.clicks'))
                    ->state(fn (PromoLink $record): int => (int) ($record->getAttribute('clicks_total') ?? 0))
                    ->sortable(),
                TextColumn::make('period')
                    ->label(__('promo_admin.links.table.period'))
                    ->state(fn (PromoLink $record): string => PromoLinkSummary::period($record))
                    ->color(fn (PromoLink $record): ?string => $record->ends_at !== null && $record->ends_at->isPast() ? 'danger' : null)
                    ->wrap()
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label(__('promo_admin.links.fields.is_active'))
                    ->disabled(fn (PromoLink $record): bool => ! Gate::allows('update', $record))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('promo_admin.links.filters.active')),
                SelectFilter::make('target_type')
                    ->label(__('promo_admin.links.filters.target_type'))
                    ->options(PromoLinkTargetType::options()),
                Filter::make('expired')
                    ->label(__('promo_admin.links.filters.expired'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('ends_at')->where('ends_at', '<', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                PromoLinkActions::copy(),
                PromoLinkActions::check(),
                PromoLinkActions::preview(),
                DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (PromoLink $record): string => PromoLinkResource::getUrl(PromoLinkResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]));
    }
}
