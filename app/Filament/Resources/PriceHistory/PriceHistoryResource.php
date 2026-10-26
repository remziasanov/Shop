<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistory;

use App\Enums\PriceChangeSource;
use App\Filament\Resources\PriceHistory\Pages\ListPriceHistory;
use App\Filament\Resources\Products\ProductResource;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Services\Pricing\PriceHistoryRecorder;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only price history (PR-06, AD-03 group «Цены»).
 */
class PriceHistoryResource extends Resource
{
    protected static ?string $model = PriceHistory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'price-history';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.prices');
    }

    public static function getModelLabel(): string
    {
        return __('admin_pricing.history.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_pricing.history.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product:id,name,sku', 'user:id,name']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin_pricing.history.columns.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label(__('admin_pricing.history.columns.product'))
                    ->description(fn (PriceHistory $record): ?string => $record->product->sku ?? null)
                    ->url(fn (PriceHistory $record): string => ProductResource::getUrl('edit', ['record' => $record->product_id]))
                    ->wrap(),
                TextColumn::make('field')
                    ->label(__('admin_pricing.history.columns.field'))
                    ->formatStateUsing(fn (string $state): string => self::fieldLabel($state)),
                TextColumn::make('change')
                    ->label(__('admin_pricing.history.columns.change'))
                    ->state(fn (PriceHistory $record): string => self::change($record))
                    ->html(false),
                TextColumn::make('source')
                    ->label(__('admin_pricing.history.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn (PriceChangeSource|string $state): string => ($state instanceof PriceChangeSource ? $state : PriceChangeSource::from($state))->label()),
                TextColumn::make('user.name')
                    ->label(__('admin_pricing.history.columns.user'))
                    ->placeholder('—'),
            ])
            ->filters([
                Filter::make('product')
                    ->schema([
                        TextInput::make('search')->label(__('admin_pricing.history.filters.product')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $search = trim((string) ($data['search'] ?? ''));

                        return $search === ''
                            ? $query
                            : $query->whereIn('product_id', Product::withTrashed()
                                ->where(fn (Builder $w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'))
                                ->select('id'));
                    })
                    ->indicateUsing(fn (array $data): array => filled($data['search'] ?? null)
                        ? [__('admin_pricing.history.filters.product').': '.$data['search']]
                        : []),
                SelectFilter::make('field')
                    ->label(__('admin_pricing.history.columns.field'))
                    ->options(self::fieldOptions()),
                SelectFilter::make('source')
                    ->label(__('admin_pricing.history.columns.source'))
                    ->options(PriceChangeSource::options()),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__('admin_pricing.history.filters.from')),
                        DatePicker::make('until')->label(__('admin_pricing.history.filters.until')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where('created_at', '>=', $data['from'].' 00:00:00'))
                        ->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where('created_at', '<=', $data['until'].' 23:59:59'))),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceHistory::route('/'),
        ];
    }

    /**
     * «Базовая цена», «Закупочная цена», … for a history field name.
     */
    public static function fieldLabel(string $field): string
    {
        return in_array($field, PriceHistoryRecorder::TRACKED_FIELDS, true)
            ? __('admin_pricing.history.fields.'.$field)
            : $field;
    }

    /**
     * @return array<string, string>
     */
    public static function fieldOptions(): array
    {
        $options = [];

        foreach (PriceHistoryRecorder::TRACKED_FIELDS as $field) {
            $options[$field] = self::fieldLabel($field);
        }

        return $options;
    }

    /**
     * «1 000 ₽ → 1 200 ₽»; a missing value (price set or cleared) shows as «—».
     */
    public static function change(PriceHistory $record): string
    {
        $format = fn (?string $value): string => $value === null ? '—' : Money::format(Money::fromRubles($value));

        return $format($record->old_value).' → '.$format($record->new_value);
    }
}
