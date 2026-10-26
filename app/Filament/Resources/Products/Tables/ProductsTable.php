<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Enums\SalesMode;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Support\BulkPriceChangeAction;
use App\Filament\Resources\Products\Support\ExportSelectedAction;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\ProductDuplicator;
use App\Services\Media\ImageProcessor;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Sales\SalesModeResolver;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Throwable;

/**
 * Product list (AD-40…AD-44).
 */
final class ProductsTable
{
    private const CHUNK = 100;

    public static function configure(Table $table): Table
    {
        return $table
            // Eager loads: no N+1 for the category and the main photo; the sales mode is resolved from the cached tree.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['category:id,name', 'mainImage']))
            ->columns(self::columns())
            ->filters(self::filters())
            ->recordActions([
                EditAction::make(),
                self::openOnSiteAction(),
                self::duplicateAction(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make(self::bulkActions()),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Product $record): ?string => $record->trashed() ? null : ProductResource::getUrl('edit', ['record' => $record]));
    }

    public static function openOnSiteAction(): Action
    {
        return Action::make('open_site')
            ->label(__('admin_products.actions.open_site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url(fn (Product $record): ?string => Route::has('product.show') ? route('product.show', [$record->slug]) : null)
            ->openUrlInNewTab()
            ->visible(fn (Product $record): bool => Route::has('product.show') && ! $record->trashed());
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('admin_products.actions.duplicate'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->visible(fn (Product $record): bool => ! $record->trashed())
            ->action(function (Product $record): void {
                try {
                    $copy = app(ProductDuplicator::class)->duplicate($record);
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->danger()->title(__('admin_products.actions.duplicate_failed'))->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_products.actions.duplicated', ['sku' => $copy->sku]))->send();
            });
    }

    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            ImageColumn::make('mainImage.path_small')
                ->label(__('admin_products.table.photo'))
                ->disk(ImageProcessor::DISK)
                ->imageHeight(48)
                ->square(),
            TextColumn::make('name')
                ->label(__('admin_products.fields.name'))
                ->searchable()
                ->sortable()
                ->limit(60)
                ->wrap(),
            TextColumn::make('sku')
                ->label(__('admin_products.fields.sku'))
                ->searchable()
                ->sortable()
                ->copyable(),
            TextColumn::make('category.name')
                ->label(__('admin_products.table.category'))
                ->sortable(),
            TextColumn::make('final_price')
                ->label(__('admin_products.table.price'))
                ->sortable()
                ->formatStateUsing(fn (string $state): string => Money::format(Money::fromRubles($state)))
                ->description(fn (Product $record): ?string => Money::fromRubles($record->base_price) !== Money::fromRubles($record->final_price)
                    ? __('admin_products.table.base_price_under', ['price' => Money::format(Money::fromRubles($record->base_price))])
                    : null),
            TextColumn::make('stock_qty')
                ->label(__('admin_products.table.stock'))
                ->sortable()
                ->state(fn (Product $record): string => $record->stock_qty === null
                    ? __('admin_products.table.stock_untracked')
                    : $record->availableQty().' / '.$record->stock_qty),
            TextColumn::make('effective_mode')
                ->label(__('admin_products.table.mode'))
                ->state(fn (Product $record): string => app(SalesModeResolver::class)->resolve($record)->label())
                ->wrap(),
            ToggleColumn::make('is_active')
                ->label(__('admin_products.fields.is_active')),
            TextColumn::make('updated_at')
                ->label(__('admin_products.fields.updated_at'))
                ->dateTime()
                ->sortable(),
        ];
    }

    /**
     * @return list<BaseFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('category_id')
                ->label(__('admin_products.filters.category'))
                ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                ->searchable()
                ->query(function (Builder $query, array $data): Builder {
                    $id = $data['value'] ?? null;

                    return filled($id)
                        ? $query->whereIn('products.category_id', app(CategoryTree::class)->descendantIds((int) $id))
                        : $query;
                }),
            TernaryFilter::make('is_active')
                ->label(__('admin_products.filters.active')),
            SelectFilter::make('stock')
                ->label(__('admin_products.filters.stock'))
                ->options([
                    'in' => __('admin_products.filters.stock_in'),
                    'out' => __('admin_products.filters.stock_out'),
                    'untracked' => __('admin_products.filters.stock_untracked'),
                ])
                ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'in' => $query->whereNotNull('stock_qty')->whereRaw('stock_qty - reserved_qty > 0'),
                    'out' => $query->whereNotNull('stock_qty')->whereRaw('stock_qty - reserved_qty <= 0'),
                    'untracked' => $query->whereNull('stock_qty'),
                    default => $query,
                }),
            // Filters on the mode set on the product itself, not on the effective (inherited) one.
            SelectFilter::make('sales_mode')
                ->label(__('admin_products.filters.sales_mode'))
                ->options(['inherit' => __('admin_products.filters.sales_mode_inherit')] + SalesMode::options())
                ->query(function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    return match (true) {
                        blank($value) => $query,
                        $value === 'inherit' => $query->whereNull('sales_mode'),
                        default => $query->where('sales_mode', $value),
                    };
                }),
            SelectFilter::make('flags')
                ->label(__('admin_products.filters.flags'))
                ->multiple()
                ->options([
                    'featured' => __('admin_products.fields.is_featured'),
                    'new' => __('admin_products.fields.is_new'),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $values = (array) ($data['values'] ?? []);

                    return $query
                        ->when(in_array('featured', $values, true), fn (Builder $q) => $q->where('is_featured', true))
                        ->when(in_array('new', $values, true), fn (Builder $q) => $q->where('is_new', true));
                }),
            Filter::make('no_photos')
                ->label(__('admin_products.filters.no_photos'))
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereDoesntHave('images')),
            Filter::make('on_sale')
                ->label(__('admin_products.filters.on_sale'))
                ->toggle()
                ->query(fn (Builder $query): Builder => $query->whereNotNull('display_old_price')),
            Filter::make('price')
                ->schema([
                    TextInput::make('from')->label(__('admin_products.filters.price_from'))->inputMode('decimal'),
                    TextInput::make('to')->label(__('admin_products.filters.price_to'))->inputMode('decimal'),
                ])
                ->columns(2)
                ->query(function (Builder $query, array $data): Builder {
                    $from = self::rubles($data['from'] ?? null);
                    $to = self::rubles($data['to'] ?? null);

                    return $query
                        ->when($from !== null, fn (Builder $q) => $q->where('final_price', '>=', $from))
                        ->when($to !== null, fn (Builder $q) => $q->where('final_price', '<=', $to));
                })
                ->indicateUsing(function (array $data): array {
                    $indicators = [];

                    if (($from = self::rubles($data['from'] ?? null)) !== null) {
                        $indicators[] = __('admin_products.filters.price_indicator_from', ['price' => Money::format(Money::fromRubles($from))]);
                    }

                    if (($to = self::rubles($data['to'] ?? null)) !== null) {
                        $indicators[] = __('admin_products.filters.price_indicator_to', ['price' => Money::format(Money::fromRubles($to))]);
                    }

                    return $indicators;
                }),
            SelectFilter::make('brand')
                ->label(__('admin_products.filters.brand'))
                ->multiple()
                ->searchable()
                ->options(fn (): array => Product::query()
                    ->whereNotNull('brand')
                    ->where('brand', '!=', '')
                    ->distinct()
                    ->orderBy('brand')
                    ->limit(500)
                    ->pluck('brand', 'brand')
                    ->all()),
            TrashedFilter::make(),
        ];
    }

    /**
     * @return list<Action|ActionGroup>
     */
    private static function bulkActions(): array
    {
        return [
            self::updateAction('activate', __('admin_products.actions.activate'), Heroicon::OutlinedEye, fn (): array => ['is_active' => true]),
            self::updateAction('deactivate', __('admin_products.actions.deactivate'), Heroicon::OutlinedEyeSlash, fn (): array => ['is_active' => false]),
            self::updateAction(
                'move',
                __('admin_products.actions.move'),
                Heroicon::OutlinedFolderArrowDown,
                fn (array $data): array => ['category_id' => (int) $data['category_id']],
                [
                    Select::make('category_id')
                        ->label(__('admin_products.fields.category'))
                        ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                        ->required()
                        ->searchable()
                        ->native(false),
                ],
                affectsPrice: true,
            ),
            self::updateAction(
                'set_mode',
                __('admin_products.actions.set_mode'),
                Heroicon::OutlinedShoppingCart,
                fn (array $data): array => ['sales_mode' => filled($data['sales_mode'] ?? null) ? (string) $data['sales_mode'] : null],
                [
                    Select::make('sales_mode')
                        ->label(__('admin_products.fields.sales_mode'))
                        ->options(SalesMode::options())
                        ->placeholder(__('admin_products.fields.sales_mode_inherit'))
                        ->native(false),
                ],
            ),
            self::updateAction(
                'set_flags',
                __('admin_products.actions.set_flags'),
                Heroicon::OutlinedTag,
                function (array $data): array {
                    $changes = [];

                    foreach (['featured' => 'is_featured', 'new' => 'is_new'] as $key => $column) {
                        $choice = $data[$key] ?? 'keep';

                        if ($choice !== 'keep') {
                            $changes[$column] = $choice === 'on';
                        }
                    }

                    return $changes;
                },
                [
                    self::flagChoice('featured', __('admin_products.actions.flag_featured')),
                    self::flagChoice('new', __('admin_products.actions.flag_new')),
                ],
                affectsPrice: true,
            ),
            BulkPriceChangeAction::make(),
            ExportSelectedAction::make(),
            DeleteBulkAction::make()->chunkSelectedRecords(self::CHUNK),
            RestoreBulkAction::make()->chunkSelectedRecords(self::CHUNK),
            // Force deletion fires the model event that removes the photo files (AD-44).
            ForceDeleteBulkAction::make()->chunkSelectedRecords(self::CHUNK),
        ];
    }

    /**
     * Bulk action that sets columns on every selected product, processed in chunks of 100 (AD-42).
     * The columns are written with a single UPDATE per chunk (no model events), so when they are inputs of price
     * rule matching (category, flags) `$affectsPrice` queues the price recalculation of the chunk (PR-02).
     *
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $changes
     * @param  list<Component>  $form
     */
    private static function updateAction(string $name, string $label, Heroicon $icon, \Closure $changes, array $form = [], bool $affectsPrice = false): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->requiresConfirmation()
            ->schema($form)
            ->chunkSelectedRecords(self::CHUNK)
            ->deselectRecordsAfterCompletion()
            ->action(function (Enumerable $records, array $data) use ($changes, $affectsPrice): void {
                $values = $changes($data);

                if ($values === []) {
                    return;
                }

                // With chunking the callback receives a lazy collection of one chunk.
                $ids = $records->map(fn (Model $record): mixed => $record->getKey())->values()->all();

                Product::withTrashed()->whereKey($ids)->update($values);

                if ($affectsPrice) {
                    app(PriceRuleRecalculator::class)->dispatch($ids);
                }

                Notification::make()->success()->title(__('admin_products.actions.done', ['count' => count($ids)]))->send();
            });
    }

    private static function flagChoice(string $name, string $label): ToggleButtons
    {
        return ToggleButtons::make($name)
            ->label($label)
            ->options([
                'keep' => __('admin_products.actions.flag_keep'),
                'on' => __('admin_products.actions.flag_on'),
                'off' => __('admin_products.actions.flag_off'),
            ])
            ->default('keep')
            ->inline();
    }

    /**
     * Normalized rubles string ("1290.00") or null for empty/invalid input.
     */
    private static function rubles(mixed $input): ?string
    {
        if (! is_string($input) || trim($input) === '') {
            return null;
        }

        try {
            return Money::toRubles(Money::fromRubles($input));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
