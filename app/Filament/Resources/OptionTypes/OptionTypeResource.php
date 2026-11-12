<?php

declare(strict_types=1);

namespace App\Filament\Resources\OptionTypes;

use App\Enums\OptionDisplayType;
use App\Exceptions\OptionException;
use App\Filament\Resources\OptionTypes\Pages\CreateOptionType;
use App\Filament\Resources\OptionTypes\Pages\EditOptionType;
use App\Filament\Resources\OptionTypes\Pages\ListOptionTypes;
use App\Filament\Resources\OptionTypes\RelationManagers\ValuesRelationManager;
use App\Models\OptionType;
use App\Services\Variants\OptionService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «Опции» under Каталог (VR-26): the dictionary of Color, Size… with their values. Writes go through
 * {@see OptionService}; abilities are the `option_types.*` permissions.
 */
class OptionTypeResource extends Resource
{
    protected static ?string $model = OptionType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static ?int $navigationSort = 26;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('admin_variants.options.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_variants.options.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin_variants.options.fields.name'))
                ->required()
                ->maxLength(100),
            TextInput::make('slug')
                ->label(__('admin_variants.options.fields.slug'))
                ->helperText(__('admin_variants.options.fields.slug_hint'))
                ->maxLength(100),
            Select::make('display_type')
                ->label(__('admin_variants.options.fields.display_type'))
                ->helperText(__('admin_variants.options.fields.display_type_hint'))
                ->options(OptionDisplayType::options())
                ->default(OptionDisplayType::Select->value)
                ->required()
                ->native(false)
                ->selectablePlaceholder(false),
            TextInput::make('sort_order')
                ->label(__('admin_variants.options.fields.sort_order'))
                ->helperText(__('admin_variants.options.fields.sort_order_hint'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(1_000_000),
            Toggle::make('is_filterable')
                ->label(__('admin_variants.options.fields.is_filterable'))
                ->helperText(__('admin_variants.options.fields.is_filterable_hint'))
                ->default(false),
            Toggle::make('is_active')
                ->label(__('admin_variants.options.fields.is_active'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('values'))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin_variants.options.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (OptionType $record): ?string => $record->is_active ? null : __('admin_variants.options.inactive')),
                TextColumn::make('display_type')
                    ->label(__('admin_variants.options.fields.display_type'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ($state instanceof OptionDisplayType ? $state : OptionDisplayType::from((string) $state))->label()),
                IconColumn::make('is_filterable')
                    ->label(__('admin_variants.options.table.filterable'))
                    ->boolean(),
                TextColumn::make('values_count')
                    ->label(__('admin_variants.options.table.values_count'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('admin_variants.options.fields.sort_order'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('display_type')
                    ->label(__('admin_variants.options.fields.display_type'))
                    ->options(OptionDisplayType::options()),
                TernaryFilter::make('is_filterable')
                    ->label(__('admin_variants.options.table.filterable')),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->recordUrl(fn (OptionType $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            ValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOptionTypes::route('/'),
            'create' => CreateOptionType::route('/create'),
            'edit' => EditOptionType::route('/{record}/edit'),
        ];
    }

    /**
     * «Удалить»: refused with the number of cards while the option is in use.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(function (OptionType $record): ?string {
                $count = app(OptionService::class)->typeUsage($record);

                return $count > 0 ? OptionException::typeInUse($record->name, $count)->getMessage() : null;
            })
            ->using(function (OptionType $record): bool {
                try {
                    app(OptionService::class)->deleteType($record);
                } catch (OptionException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return false;
                }

                return true;
            })
            ->failureNotification(null);
    }
}
