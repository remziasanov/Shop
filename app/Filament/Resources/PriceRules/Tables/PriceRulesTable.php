<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Tables;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Filament\Resources\PriceRules\PriceRuleResource;
use App\Filament\Resources\PriceRules\Support\PriceRuleSummary;
use App\Models\PriceRule;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Pricing\PriceRuleSaver;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Price rule list (TZ 6.8). The active toggle works for administrators only.
 */
final class PriceRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Targets are read for the scope summary; the category names come from the cached tree.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('targets'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin_pricing.rules.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('action')
                    ->label(__('admin_pricing.rules.table.action'))
                    ->state(fn (PriceRule $record): string => PriceRuleSummary::action($record->action, $record->value))
                    ->sortable(),
                TextColumn::make('scope')
                    ->label(__('admin_pricing.rules.fields.scope'))
                    ->state(fn (PriceRule $record): string => PriceRuleSummary::scope($record, app(CategoryTree::class)))
                    ->wrap()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label(__('admin_pricing.rules.fields.priority'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('period')
                    ->label(__('admin_pricing.rules.table.period'))
                    ->state(fn (PriceRule $record): string => PriceRuleSummary::period($record))
                    ->wrap(),
                ToggleColumn::make('is_active')
                    ->label(__('admin_pricing.rules.fields.is_active'))
                    ->disabled(fn (): bool => ! self::isAdmin())
                    ->afterStateUpdated(fn (PriceRule $record) => app(PriceRuleSaver::class)->toggled($record))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('admin_pricing.rules.table.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin_pricing.rules.filters.active')),
                SelectFilter::make('action')
                    ->label(__('admin_pricing.rules.fields.action'))
                    ->options(PriceRuleAction::options()),
                SelectFilter::make('scope')
                    ->label(__('admin_pricing.rules.fields.scope'))
                    ->options(PriceRuleScope::options()),
                Filter::make('in_effect')
                    ->label(__('admin_pricing.rules.filters.in_effect'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->scopes(['inEffect' => [now()]])),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                PriceRuleResource::deleteAction(),
            ])
            ->defaultSort('priority', 'desc')
            ->recordUrl(fn (PriceRule $record): string => PriceRuleResource::getUrl(PriceRuleResource::canEdit($record) ? 'edit' : 'view', ['record' => $record]));
    }

    private static function isAdmin(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }
}
