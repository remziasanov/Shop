<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories;

use App\Enums\SalesMode;
use App\Exceptions\CategoryTreeException;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Media\CategoryImageImporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Route;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('admin_categories.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_categories.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin_categories.sections.main'))->schema([
                TextInput::make('name')
                    ->label(__('admin_categories.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('admin_categories.fields.slug'))
                    ->helperText(__('admin_categories.fields.slug_hint'))
                    ->maxLength(240),
                Select::make('parent_id')
                    ->label(__('admin_categories.fields.parent'))
                    ->placeholder(__('admin_categories.fields.parent_placeholder'))
                    ->options(fn (?Category $record): array => app(CategoryTree::class)->parentOptions($record))
                    ->searchable()
                    ->native(false),
                Select::make('sales_mode')
                    ->label(__('admin_categories.fields.sales_mode'))
                    ->placeholder(__('admin_categories.fields.sales_mode_inherit'))
                    ->options(SalesMode::options())
                    ->native(false),
                Toggle::make('is_active')
                    ->label(__('admin_categories.fields.is_active'))
                    ->default(true),
                TextInput::make('sort_order')
                    ->label(__('admin_categories.fields.sort_order'))
                    ->helperText(__('admin_categories.fields.sort_order_hint'))
                    ->numeric()
                    ->integer()
                    ->minValue(-1_000_000)
                    ->maxValue(1_000_000)
                    ->default(0)
                    ->required(),
                RichEditor::make('description')
                    ->label(__('admin_categories.fields.description'))
                    ->dehydrateStateUsing(fn (mixed $state): ?string => self::sanitize($state))
                    ->columnSpanFull(),
                // Current image (edit page only): the upload below is processed on save (AD-31).
                ImageEntry::make('image_path')
                    ->label(__('admin_categories.fields.image_current'))
                    ->disk('public')
                    ->visible(fn (?Category $record): bool => filled($record?->image_path)),
                Toggle::make('remove_image')
                    ->label(__('admin_categories.fields.image_remove'))
                    ->default(false)
                    ->visible(fn (?Category $record): bool => filled($record?->image_path)),
                FileUpload::make('new_image')
                    ->label(__('admin_categories.fields.image'))
                    ->helperText(__('admin_categories.fields.image_hint'))
                    ->image()
                    ->disk(CategoryImageImporter::TEMP_DISK)
                    ->directory(CategoryImageImporter::TEMP_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->maxSize(intdiv(PhotoUpload::effectiveMaxBytes(), 1024))
                    ->columnSpanFull(),
            ])->columns(2),
            Section::make(__('admin_categories.sections.seo'))->schema([
                TextInput::make('meta_title')
                    ->label(__('admin_categories.fields.meta_title'))
                    ->maxLength(255),
                Textarea::make('meta_description')
                    ->label(__('admin_categories.fields.meta_description'))
                    ->rows(3)
                    ->maxLength(255),
            ])->columns(1)->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tree = app(CategoryTree::class);

        /** @var array<int, int>|null $counts */
        $counts = null;

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::orderByTree($query, $tree))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin_categories.table.name'))
                    ->searchable()
                    ->formatStateUsing(fn (string $state, Category $record): string => str_repeat('— ', max(0, $record->depth - 1)).$state)
                    ->description(fn (Category $record): ?string => $record->depth > 1 ? self::fullPath($record, $tree) : null),
                TextColumn::make('products_count')
                    ->label(__('admin_categories.table.products_count'))
                    ->tooltip(__('admin_categories.table.products_count_hint'))
                    ->alignEnd()
                    ->state(function (Category $record) use (&$counts, $tree): int {
                        $counts ??= $tree->productCountsWithDescendants();

                        return $counts[$record->id] ?? 0;
                    }),
                TextColumn::make('sales_mode')
                    ->label(__('admin_categories.table.sales_mode'))
                    ->badge()
                    ->state(fn (Category $record): string => $record->sales_mode?->label() ?? __('admin_categories.table.sales_mode_inherit'))
                    ->color(fn (Category $record): string => $record->sales_mode === null ? 'gray' : 'primary'),
                ToggleColumn::make('is_active')
                    ->label(__('admin_categories.table.is_active')),
            ])
            ->filters([
                Filter::make('parent')
                    ->schema([
                        Select::make('parent')
                            ->label(__('admin_categories.table.filter_parent'))
                            ->options(['root' => __('admin_categories.table.filter_root')] + $tree->options())
                            ->searchable()
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $parent = $data['parent'] ?? null;

                        return match (true) {
                            $parent === 'root' => $query->whereNull('parent_id'),
                            filled($parent) => $query->where('parent_id', (int) $parent),
                            default => $query,
                        };
                    })
                    ->indicateUsing(function (array $data) use ($tree): array {
                        $parent = $data['parent'] ?? null;

                        if ($parent === null || $parent === '') {
                            return [];
                        }

                        $label = $parent === 'root' ? __('admin_categories.table.filter_root') : ($tree->options()[(int) $parent] ?? '');

                        return [__('admin_categories.table.filter_parent').': '.$label];
                    }),
            ])
            // Drag and drop only makes sense among siblings, so it is enabled once a parent is chosen (AD-30).
            ->reorderable('sort_order', fn ($livewire): bool => filled($livewire->tableFilters['parent']['parent'] ?? null))
            ->afterReordering(fn () => $tree->flush())
            ->recordActions([
                self::openOnSiteAction(),
                EditAction::make(),
                self::deleteAction(),
            ])
            ->toolbarActions([
                self::bulkDeleteAction(),
            ])
            ->emptyStateHeading(__('admin_categories.plural'))
            ->recordUrl(fn (Category $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * AD-05: the category page on the storefront in a new tab (hidden until the storefront route exists).
     */
    public static function openOnSiteAction(): Action
    {
        return Action::make('open_on_site')
            ->label(__('admin_categories.actions.open_on_site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (): bool => Route::has('catalog.category'))
            ->url(fn (Category $record): ?string => Route::has('catalog.category') ? route('catalog.category', $record->slug) : null)
            ->openUrlInNewTab();
    }

    /**
     * AD-33: an empty category is deleted as usual; otherwise products and subcategories move to the parent.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Category $record): string => self::hasContent($record)
                ? __('admin_categories.actions.delete_moving')
                : __('admin_categories.actions.delete_plain'))
            ->modalDescription(function (Category $record): ?string {
                if (! self::hasContent($record)) {
                    return null;
                }

                if ($record->parent_id === null && self::productsIn($record) > 0) {
                    return CategoryTreeException::cannotMoveProductsToRoot()->getMessage();
                }

                return __('admin_categories.actions.delete_moving_description', [
                    'children' => $record->children()->count(),
                    'products' => self::productsIn($record),
                ]);
            })
            ->modalSubmitActionLabel(fn (Category $record): string => self::hasContent($record)
                ? __('admin_categories.actions.delete_moving_submit')
                : __('filament-actions::delete.single.modal.actions.delete.label'))
            ->using(function (Category $record): bool {
                $moving = self::hasContent($record);

                try {
                    self::deleteCategory($record);
                } catch (CategoryTreeException $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('admin_categories.notifications.delete_failed'))
                        ->body($e->getMessage())
                        ->send();

                    return false;
                }

                Notification::make()
                    ->success()
                    ->title($moving ? __('admin_categories.notifications.deleted_moved') : __('admin_categories.notifications.deleted'))
                    ->send();

                return true;
            })
            ->successNotification(null);
    }

    /**
     * Bulk delete removes only empty categories (deepest first); the rest is reported and left alone.
     */
    public static function bulkDeleteAction(): BulkAction
    {
        return BulkAction::make('delete')
            ->label(__('admin_categories.actions.bulk_delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $deleted = 0;
                $skipped = 0;

                foreach ($records->sortByDesc('depth') as $record) {
                    /** @var Category $record */
                    try {
                        app(CategoryTree::class)->assertEmpty($record);
                        $record->delete();
                        $deleted++;
                    } catch (CategoryTreeException) {
                        $skipped++;
                    }
                }

                if ($deleted > 0) {
                    Notification::make()->success()->title(__('admin_categories.actions.bulk_deleted', ['count' => $deleted]))->send();
                }

                if ($skipped > 0) {
                    Notification::make()->warning()->title(__('admin_categories.actions.bulk_skipped', ['count' => $skipped]))->send();
                }
            });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @throws CategoryTreeException
     */
    private static function deleteCategory(Category $category): void
    {
        $tree = app(CategoryTree::class);

        if (self::hasContent($category)) {
            $tree->deleteMovingToParent($category);

            return;
        }

        $category->delete();
    }

    private static function hasContent(Category $category): bool
    {
        return $category->children()->exists() || self::productsIn($category) > 0;
    }

    private static function productsIn(Category $category): int
    {
        return Product::withTrashed()->where('category_id', $category->id)->count();
    }

    private static function sanitize(mixed $state): ?string
    {
        $html = is_string($state) ? app(HtmlSanitizer::class)->sanitize($state) : '';

        return $html === '' ? null : $html;
    }

    private static function fullPath(Category $category, CategoryTree $tree): string
    {
        return implode(__('catalog.categories.path_separator'), array_map(
            fn ($node): string => $node->name,
            $tree->breadcrumbs($category),
        ));
    }

    /**
     * Children follow their parent (AD-30): order by position in the cached depth-first tree walk.
     * A CASE expression keeps it portable (SQLite, MySQL 5.7), and category lists are small.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    private static function orderByTree(Builder $query, CategoryTree $tree): Builder
    {
        $ids = [];
        $walk = function ($node) use (&$walk, &$ids): void {
            $ids[] = $node->id;
            foreach ($node->children as $child) {
                $walk($child);
            }
        };

        foreach ($tree->roots() as $root) {
            $walk($root);
        }

        if ($ids === []) {
            return $query;
        }

        $cases = '';
        foreach ($ids as $position => $id) {
            $cases .= ' WHEN '.(int) $id.' THEN '.$position;
        }

        return $query->orderByRaw('CASE '.$query->getModel()->qualifyColumn('id').$cases.' ELSE '.count($ids).' END');
    }
}
