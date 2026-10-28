<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems;

use App\Enums\MenuLinkType;
use App\Enums\MenuLocation;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Models\MenuItem;
use App\Models\Page;
use App\Services\Catalog\CategoryTree;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * AD-61: header and footer menus. Administrators and managers (TZ 6.13).
 */
class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'label';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.content');
    }

    public static function getModelLabel(): string
    {
        return __('admin_content.menu.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_content.menu.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location')
                ->label(__('admin_content.menu.fields.location'))
                ->options(MenuLocation::options())
                ->default(MenuLocation::Header->value)
                ->required()
                ->native(false),
            TextInput::make('label')
                ->label(__('admin_content.menu.fields.label'))
                ->required()
                ->maxLength(255),
            Select::make('link_type')
                ->label(__('admin_content.menu.fields.link_type'))
                ->options(MenuLinkType::options())
                ->default(MenuLinkType::Category->value)
                ->required()
                ->live()
                ->native(false),
            Select::make('category_id')
                ->label(__('admin_content.menu.fields.category'))
                ->options(fn (): array => app(CategoryTree::class)->options())
                ->searchable()
                ->native(false)
                ->required(fn (Get $get): bool => self::type($get) === MenuLinkType::Category)
                ->visible(fn (Get $get): bool => self::type($get) === MenuLinkType::Category),
            Select::make('page_id')
                ->label(__('admin_content.menu.fields.page'))
                ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->native(false)
                ->required(fn (Get $get): bool => self::type($get) === MenuLinkType::Page)
                ->visible(fn (Get $get): bool => self::type($get) === MenuLinkType::Page),
            TextInput::make('url')
                ->label(__('admin_content.menu.fields.url'))
                ->helperText(__('admin_content.menu.fields.url_hint'))
                ->maxLength(500)
                ->required(fn (Get $get): bool => self::type($get) === MenuLinkType::Url)
                ->visible(fn (Get $get): bool => self::type($get) === MenuLinkType::Url)
                ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! MenuItem::isSafeUrl($value)) {
                        $fail(__('admin_content.menu.fields.url_invalid'));
                    }
                }),
            TextInput::make('sort_order')
                ->label(__('admin_content.menu.fields.sort_order'))
                ->numeric()
                ->integer()
                ->minValue(-1_000_000)
                ->maxValue(1_000_000)
                ->default(0)
                ->required(),
            Toggle::make('is_active')
                ->label(__('admin_content.menu.fields.is_active'))
                ->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['category', 'page']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin_content.menu.table.label'))
                    ->searchable(),
                TextColumn::make('link_type')
                    ->label(__('admin_content.menu.table.link_type'))
                    ->badge()
                    ->formatStateUsing(fn (MenuLinkType $state): string => $state->label()),
                TextColumn::make('target')
                    ->label(__('admin_content.menu.table.target'))
                    ->state(fn (MenuItem $record): ?string => match ($record->link_type) {
                        MenuLinkType::Category => $record->category?->name,
                        MenuLinkType::Page => $record->page?->title,
                        MenuLinkType::Url => $record->url,
                    }),
                ToggleColumn::make('is_active')
                    ->label(__('admin_content.menu.table.is_active'))
                    ->disabled(fn (MenuItem $record): bool => ! Gate::allows('update', $record)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ])
            ->description(__('admin_content.menu.table.reorder_hint'))
            ->recordUrl(fn (MenuItem $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }

    private static function type(Get $get): ?MenuLinkType
    {
        $value = $get('link_type');

        return $value instanceof MenuLinkType ? $value : MenuLinkType::tryFrom((string) $value);
    }
}
