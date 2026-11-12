<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes;

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Attributes\Pages\EditAttribute;
use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Filament\Resources\Attributes\RelationManagers\ValuesRelationManager;
use App\Models\Attribute;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\CategoryFilterEditor;
use App\Services\Catalog\CategoryTree;
use App\Services\Users\PermissionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
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
 * «Характеристики» under Каталог (FL-17): list, form, values manager, bulk inclusion into category filter sets, deletion
 * by FL-20. Every write goes through {@see AttributeService}; abilities are the `attributes.*` permissions.
 */
class AttributeResource extends Resource
{
    protected static ?string $model = Attribute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('admin_attributes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_attributes.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin_attributes.fields.name'))
                ->required()
                ->maxLength(150),
            TextInput::make('slug')
                ->label(__('admin_attributes.fields.slug'))
                ->helperText(__('admin_attributes.fields.slug_hint'))
                ->maxLength(180),
            // FL-01: the type cannot change while products have values.
            Select::make('type')
                ->label(__('admin_attributes.fields.type'))
                ->options(AttributeType::options())
                ->default(AttributeType::List->value)
                ->required()
                ->native(false)
                ->live()
                ->disabled(fn (?Attribute $record): bool => self::typeLocked($record))
                ->helperText(fn (?Attribute $record): string => self::typeLocked($record)
                    ? AttributeException::typeLocked(self::usage($record))->getMessage()
                    : __('admin_attributes.fields.type_hint')),
            TextInput::make('unit')
                ->label(__('admin_attributes.fields.unit'))
                ->helperText(__('admin_attributes.fields.unit_hint'))
                ->maxLength(20)
                ->visible(fn (Get $get): bool => (string) $get('type') === AttributeType::Number->value),
            TextInput::make('sort_order')
                ->label(__('admin_attributes.fields.sort_order'))
                ->helperText(__('admin_attributes.fields.sort_order_hint'))
                ->numeric()
                ->integer()
                ->minValue(-1_000_000)
                ->maxValue(1_000_000),
            Toggle::make('is_filterable')
                ->label(__('admin_attributes.fields.is_filterable'))
                ->helperText(__('admin_attributes.fields.is_filterable_hint'))
                ->default(true),
            Toggle::make('index_single_value')
                ->label(__('admin_attributes.fields.index_single_value'))
                ->helperText(__('admin_attributes.fields.index_single_value_hint'))
                ->default(false)
                ->visible(fn (Get $get): bool => (string) $get('type') === AttributeType::List->value),
            Toggle::make('is_active')
                ->label(__('admin_attributes.fields.is_active'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Counts as subqueries of the page query: no query per row (FL-17).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select('attributes.*')
                ->withCount('values')
                ->addSelect(['products_count' => ProductAttributeValue::query()
                    ->selectRaw('count(distinct product_id)')
                    ->whereColumn('product_attribute_values.attribute_id', 'attributes.id')]))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin_attributes.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Attribute $record): ?string => $record->is_active ? null : __('admin_attributes.inactive')),
                TextColumn::make('type')
                    ->label(__('admin_attributes.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => ($state instanceof AttributeType ? $state : AttributeType::from((string) $state))->label()),
                TextColumn::make('unit')
                    ->label(__('admin_attributes.table.unit'))
                    ->placeholder('—'),
                IconColumn::make('is_filterable')
                    ->label(__('admin_attributes.table.filterable'))
                    ->boolean(),
                TextColumn::make('values_count')
                    ->label(__('admin_attributes.table.values_count'))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label(__('admin_attributes.table.products_count'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin_attributes.fields.type'))
                    ->options(AttributeType::options()),
                TernaryFilter::make('is_filterable')
                    ->label(__('admin_attributes.table.filterable')),
            ])
            ->recordActions([
                EditAction::make(),
                self::showInFiltersAction(),
                self::detachAndDeleteAction(),
                self::deleteAction(),
            ])
            ->recordUrl(fn (Attribute $record): string => static::getUrl('edit', ['record' => $record]));
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
            'index' => ListAttributes::route('/'),
            'create' => CreateAttribute::route('/create'),
            'edit' => EditAttribute::route('/{record}/edit'),
        ];
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * «Показать в фильтрах категорий…» (FL-17): adds the characteristic to the own filter sets of the chosen categories.
     */
    public static function showInFiltersAction(): Action
    {
        return Action::make('show_in_filters')
            ->label(__('admin_attributes.actions.show_in_filters'))
            ->icon(Heroicon::OutlinedFunnel)
            ->color('gray')
            ->visible(fn (): bool => self::allows('attributes.update') && self::allows('categories.update'))
            ->modalHeading(fn (Attribute $record): string => __('admin_attributes.actions.show_in_filters_heading', ['name' => $record->name]))
            ->modalDescription(__('admin_attributes.actions.show_in_filters_description'))
            ->schema([
                Select::make('categories')
                    ->label(__('admin_attributes.actions.categories'))
                    ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                    ->multiple()
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->action(function (Attribute $record, array $data): void {
                abort_unless(self::allows('attributes.update') && self::allows('categories.update'), 403);

                try {
                    $result = app(CategoryFilterEditor::class)->addAttributeToCategories($record, array_map('intval', (array) ($data['categories'] ?? [])));
                } catch (AttributeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $notification = Notification::make()
                    ->title(__('admin_attributes.actions.show_in_filters_done', $result));

                ($result['full'] > 0 ? $notification->warning() : $notification->success())->send();
            });
    }

    /**
     * «Удалить» (FL-20): refused with the number of products while the characteristic is in use.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(function (Attribute $record): ?string {
                $count = self::usage($record);

                return $count > 0 ? AttributeException::inUse($count)->getMessage() : null;
            })
            ->using(function (Attribute $record): bool {
                try {
                    app(AttributeService::class)->deleteAttribute($record);
                } catch (AttributeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return false;
                }

                return true;
            })
            ->failureNotification(null);
    }

    /**
     * «Отвязать от товаров и удалить» (FL-20): shown while products use the characteristic; confirms the number of
     * affected products. The JSON cache of those products is rebuilt by queued jobs.
     */
    public static function detachAndDeleteAction(): DeleteAction
    {
        return DeleteAction::make('detach_delete')
            ->label(__('admin_attributes.actions.detach_delete'))
            ->visible(fn (Attribute $record): bool => self::allows('attributes.delete') && self::usage($record) > 0)
            ->modalHeading(__('admin_attributes.actions.detach_delete'))
            ->modalDescription(fn (Attribute $record): string => __('admin_attributes.actions.detach_delete_description', [
                'name' => $record->name,
                'count' => self::usage($record),
            ]))
            ->using(function (Attribute $record): bool {
                abort_unless(self::allows('attributes.delete'), 403);

                app(AttributeService::class)->deleteAttribute($record, true);

                return true;
            });
    }

    public static function typeLocked(?Attribute $record): bool
    {
        return $record !== null && $record->exists && self::usage($record) > 0;
    }

    /**
     * Products using the characteristic: the `products_count` the table query already selected (no query per row),
     * counted only for a record that comes without it (forms).
     */
    private static function usage(Attribute $record): int
    {
        $selected = $record->getAttributes()['products_count'] ?? null;

        return $selected !== null ? (int) $selected : app(AttributeService::class)->usageCount($record);
    }

    private static function allows(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, $permission);
    }
}
