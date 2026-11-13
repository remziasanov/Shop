<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Exceptions\VariantException;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Rules\RublesAmount;
use App\Services\Users\PermissionService;
use App\Services\Variants\CombinationGenerator;
use App\Services\Variants\VariantLabel;
use App\Services\Variants\VariantService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Illuminate\Validation\ValidationException;

/**
 * The «Модификации» tab of a card with variants (VR-02, VR-05, VR-24): the table with inline editing (article, barcode,
 * price, stock, activity, order), the combination generator, duplicate and delete, and the bulk actions «Задать остаток»,
 * «Задать цену», «Активировать» and «Скрыть». Every write goes through {@see VariantService} /
 * {@see CombinationGenerator}.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    /** @var array<int, string>|null variant id => label «Цвет: красный, Размер: M» of the current page */
    private ?array $labels = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin_variants.variants.title');
    }

    /**
     * Visible while the card works with variants (VR-24).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Product
            && $ownerRecord->has_variants
            && ! $ownerRecord->trashed()
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->reorder()->orderBy('sort_order')->orderBy('id'))
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('label')
                    ->label(__('admin_variants.variants.columns.combination'))
                    ->state(fn (ProductVariant $record): string => $this->label($record))
                    ->wrap(),
                TextInputColumn::make('sku')
                    ->label(__('admin_variants.variants.columns.sku'))
                    ->rules(['required', 'string', 'max:100'])
                    ->disabled(fn (): bool => ! $this->mayEdit())
                    ->updateStateUsing(fn (ProductVariant $record, mixed $state): mixed => $this->inline($record, 'sku', $state)),
                TextInputColumn::make('barcode')
                    ->label(__('admin_variants.variants.columns.barcode'))
                    ->rules(['nullable', 'string', 'max:32'])
                    ->disabled(fn (): bool => ! $this->mayEdit())
                    ->updateStateUsing(fn (ProductVariant $record, mixed $state): mixed => $this->inline($record, 'barcode', $state)),
                TextInputColumn::make('base_price')
                    ->label(__('admin_variants.variants.columns.price'))
                    ->placeholder(fn (): string => Money::toRubles(Money::fromRubles($this->product()->base_price)))
                    ->inputMode('decimal')
                    ->rules(fn (): array => [new RublesAmount])
                    ->disabled(fn (): bool => ! $this->mayEdit())
                    ->updateStateUsing(fn (ProductVariant $record, mixed $state): mixed => $this->inline($record, 'base_price', $state)),
                TextColumn::make('final_price')
                    ->label(__('admin_variants.variants.columns.final_price'))
                    ->formatStateUsing(fn (string $state): string => Money::format(Money::fromRubles($state)))
                    ->alignEnd(),
                TextInputColumn::make('stock_qty')
                    ->label(__('admin_variants.variants.columns.stock'))
                    ->placeholder(__('admin_variants.variants.untracked'))
                    ->inputMode('numeric')
                    ->rules(['nullable', 'integer', 'min:0', 'max:2000000000'])
                    ->disabled(fn (): bool => ! $this->mayEdit())
                    ->updateStateUsing(fn (ProductVariant $record, mixed $state): mixed => $this->inline($record, 'stock_qty', $state)),
                TextColumn::make('reserved_qty')
                    ->label(__('admin_variants.variants.columns.reserved'))
                    ->alignEnd(),
                ToggleColumn::make('is_active')
                    ->label(__('admin_variants.variants.columns.active'))
                    ->disabled(fn (): bool => ! $this->mayEdit())
                    ->updateStateUsing(fn (ProductVariant $record, mixed $state): mixed => $this->inline($record, 'is_active', (bool) $state)),
                TextInputColumn::make('sort_order')
                    ->label(__('admin_variants.variants.columns.sort'))
                    ->inputMode('numeric')
                    ->rules(['required', 'integer', 'min:-1000000', 'max:1000000'])
                    ->disabled(fn (): bool => ! $this->mayEdit())
                    ->updateStateUsing(fn (ProductVariant $record, mixed $state): mixed => $this->inline($record, 'sort_order', $state === null ? 0 : (int) $state)),
            ])
            ->headerActions([
                $this->generateAction(),
                $this->addAction(),
            ])
            ->recordActions([
                $this->pricesAction(),
                $this->duplicateAction(),
                $this->deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->setStockAction(),
                    $this->setPriceAction(),
                    $this->activeAction('activate', true),
                    $this->activeAction('hide', false),
                ]),
            ])
            ->paginated([25, 50, 100]);
    }

    // ------------------------------------------------------------------
    // Inline editing
    // ------------------------------------------------------------------

    /**
     * Writes one inline-edited field through VariantService; a refusal comes back as the cell error.
     *
     * @return mixed the stored state, or `['error' => message]`
     */
    private function inline(ProductVariant $variant, string $field, mixed $state): mixed
    {
        if (! $this->mayEdit()) {
            return ['error' => __('admin_variants.variants.forbidden')];
        }

        if ($field === 'stock_qty' && $state !== null) {
            $state = (int) $state;
        }

        try {
            $fresh = app(VariantService::class)->update($variant, [$field => $state]);
        } catch (VariantException $e) {
            return ['error' => $e->getMessage()];
        } catch (ValidationException $e) {
            return ['error' => (string) collect($e->errors())->flatten()->first()];
        }

        $this->labels = null;

        return $fresh->getAttribute($field);
    }

    // ------------------------------------------------------------------
    // Generator (VR-05)
    // ------------------------------------------------------------------

    /**
     * «Создать комбинации»: values per axis, a SKU template, the preview «будет создано N, уже есть M» with the editable
     * list of articles, then creation of the missing variants.
     */
    private function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('admin_variants.generator.action'))
            ->icon(Heroicon::OutlinedSquares2x2)
            ->modalHeading(__('admin_variants.generator.heading'))
            ->modalDescription(__('admin_variants.generator.description', ['max' => app(VariantService::class)->maxVariants()]))
            ->modalWidth('5xl')
            ->modalSubmitActionLabel(__('admin_variants.generator.submit'))
            ->visible(fn (): bool => $this->mayEdit() && $this->axes() !== [])
            ->schema(fn (): array => $this->generatorSchema())
            ->action(function (array $data): void {
                abort_unless($this->mayEdit(), 403);

                $product = $this->product();
                $generator = app(CombinationGenerator::class);
                $rows = [];

                foreach ((array) ($data['rows'] ?? []) as $row) {
                    if (is_array($row) && is_array($row['values'] ?? null)) {
                        $rows[] = ['values' => array_map('intval', $row['values']), 'sku' => (string) ($row['sku'] ?? '')];
                    }
                }

                try {
                    if ($rows === []) {
                        $rows = $generator->preview($product, $this->selection($data), $this->template($data))->editableList();
                    }

                    $created = $generator->create($product, $rows);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                    throw new Halt;
                }

                $this->labels = null;
                Notification::make()->success()->title(__('admin_variants.generator.created', ['count' => count($created)]))->send();
            });
    }

    /**
     * @return list<Component>
     */
    private function generatorSchema(): array
    {
        $fields = [];
        $refresh = function (Get $get, Set $set): void {
            $set('rows', $this->planRows($this->selectionFrom($get), (string) ($get('template') ?: CombinationGenerator::DEFAULT_TEMPLATE)));
        };

        foreach ($this->axes() as $axis) {
            $fields[] = Select::make('axis_'.$axis->id)
                ->label($axis->name)
                ->multiple()
                ->options(fn (): array => $this->valueOptions($axis->id))
                ->searchable()
                ->native(false)
                ->live()
                ->afterStateUpdated($refresh);
        }

        $fields[] = TextInput::make('template')
            ->label(__('admin_variants.generator.template'))
            ->helperText(__('admin_variants.generator.template_hint'))
            ->default(CombinationGenerator::DEFAULT_TEMPLATE)
            ->maxLength(100)
            ->live(onBlur: true)
            ->afterStateUpdated($refresh);

        $fields[] = Text::make(fn (Get $get): string => $this->summaryLine($this->selectionFrom($get), (string) ($get('template') ?: CombinationGenerator::DEFAULT_TEMPLATE)))
            ->weight('bold');

        $fields[] = Repeater::make('rows')
            ->label(__('admin_variants.generator.rows'))
            ->schema([
                Hidden::make('values'),
                TextInput::make('label')
                    ->label(__('admin_variants.generator.combination'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('sku')
                    ->label(__('admin_variants.variants.columns.sku'))
                    ->required()
                    ->maxLength(100),
            ])
            ->columns(2)
            ->addable(false)
            ->reorderable(false)
            ->default([])
            ->columnSpanFull();

        return $fields;
    }

    /**
     * @param  array<int, list<int>>  $selected
     * @return list<array{values: list<int>, sku: string, label: string}>
     */
    private function planRows(array $selected, string $template): array
    {
        try {
            $plan = app(CombinationGenerator::class)->preview($this->product(), $selected, $template);
        } catch (VariantException) {
            return [];
        }

        return array_map(fn ($row): array => ['values' => $row->valueIds, 'sku' => $row->sku, 'label' => $row->label], $plan->toCreate());
    }

    /**
     * @param  array<int, list<int>>  $selected
     */
    private function summaryLine(array $selected, string $template): string
    {
        try {
            $plan = app(CombinationGenerator::class)->preview($this->product(), $selected, $template);
        } catch (VariantException $e) {
            return $e->getMessage();
        }

        $line = $plan->summary();

        if ($plan->exceedsLimit()) {
            $line .= '. '.__('admin_variants.generator.limit', ['max' => $plan->limit, 'total' => $plan->currentCount + $plan->toCreateCount()]);
        } elseif ($plan->hasSkuConflicts()) {
            $line .= '. '.__('admin_variants.generator.sku_conflicts');
        }

        return $line;
    }

    /**
     * @return array<int, list<int>>
     */
    private function selectionFrom(Get $get): array
    {
        $selected = [];

        foreach ($this->axes() as $axis) {
            $selected[$axis->id] = array_map('intval', (array) ($get('axis_'.$axis->id) ?? []));
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, list<int>>
     */
    private function selection(array $data): array
    {
        $selected = [];

        foreach ($this->axes() as $axis) {
            $selected[$axis->id] = array_map('intval', (array) ($data['axis_'.$axis->id] ?? []));
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function template(array $data): string
    {
        return (string) (($data['template'] ?? '') ?: CombinationGenerator::DEFAULT_TEMPLATE);
    }

    // ------------------------------------------------------------------
    // Single variant actions
    // ------------------------------------------------------------------

    /**
     * «Добавить модификацию»: one value per axis, an article and the stock.
     */
    private function addAction(): Action
    {
        return Action::make('add')
            ->label(__('admin_variants.variants.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->color('gray')
            ->visible(fn (): bool => $this->mayEdit() && $this->axes() !== [])
            ->modalHeading(__('admin_variants.variants.add'))
            ->schema(fn (): array => [
                ...$this->axisSelects(),
                TextInput::make('sku')
                    ->label(__('admin_variants.variants.columns.sku'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('stock_qty')
                    ->label(__('admin_variants.variants.columns.stock'))
                    ->helperText(__('admin_variants.variants.stock_hint'))
                    ->numeric()->integer()->minValue(0)->maxValue(2_000_000_000),
            ])
            ->action(function (array $data): void {
                abort_unless($this->mayEdit(), 403);

                $values = [];

                foreach ($this->axes() as $axis) {
                    $values[] = (int) ($data['axis_'.$axis->id] ?? 0);
                }

                try {
                    app(VariantService::class)->create($this->product(), [
                        'values' => $values,
                        'sku' => (string) $data['sku'],
                        'stock_qty' => filled($data['stock_qty'] ?? null) ? (int) $data['stock_qty'] : null,
                    ]);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                    throw new Halt;
                }

                $this->labels = null;
            });
    }

    /**
     * «Цены»: the own prices of a variant (empty = the card's price).
     */
    private function pricesAction(): Action
    {
        return Action::make('prices')
            ->label(__('admin_variants.variants.prices'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('gray')
            ->visible(fn (): bool => $this->mayEdit())
            ->modalHeading(fn (ProductVariant $record): string => __('admin_variants.variants.prices_heading', ['sku' => $record->sku]))
            ->modalDescription(__('admin_variants.variants.prices_description'))
            ->fillForm(fn (ProductVariant $record): array => collect(VariantService::PRICE_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $record->getRawOriginal($field)])->all())
            ->schema(collect(VariantService::PRICE_FIELDS)->map(fn (string $field): TextInput => TextInput::make($field)
                ->label(__('variants.fields.'.$field))
                ->inputMode('decimal')
                ->rules([new RublesAmount])
                ->maxLength(20))->all())
            ->action(function (ProductVariant $record, array $data): void {
                abort_unless($this->mayEdit(), 403);

                try {
                    app(VariantService::class)->update($record, collect($data)->only(VariantService::PRICE_FIELDS)->all());
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                }
            });
    }

    /**
     * «Дублировать»: the copy takes other option values (a combination exists once), a «-copy» article and zero stock.
     */
    private function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('admin_variants.variants.duplicate'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (): bool => $this->mayEdit())
            ->modalHeading(fn (ProductVariant $record): string => __('admin_variants.variants.duplicate_heading', ['sku' => $record->sku]))
            ->modalDescription(__('admin_variants.variants.duplicate_description'))
            ->fillForm(fn (ProductVariant $record): array => $this->currentValues($record))
            ->schema(fn (): array => $this->axisSelects())
            ->action(function (ProductVariant $record, array $data): void {
                abort_unless($this->mayEdit(), 403);

                $values = [];

                foreach ($this->axes() as $axis) {
                    $values[] = (int) ($data['axis_'.$axis->id] ?? 0);
                }

                try {
                    $copy = app(VariantService::class)->duplicate($record, $values);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                    throw new Halt;
                }

                $this->labels = null;
                Notification::make()->success()->title(__('admin_variants.variants.duplicated', ['sku' => $copy->sku]))->send();
            });
    }

    private function deleteAction(): Action
    {
        return Action::make('delete_variant')
            ->label(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('admin_variants.variants.delete_description'))
            ->visible(fn (): bool => $this->mayEdit())
            ->action(function (ProductVariant $record): void {
                abort_unless($this->mayEdit(), 403);

                app(VariantService::class)->delete($record);
                $this->labels = null;
            });
    }

    // ------------------------------------------------------------------
    // Bulk actions (VR-24)
    // ------------------------------------------------------------------

    private function setStockAction(): BulkAction
    {
        return BulkAction::make('set_stock')
            ->label(__('admin_variants.bulk.set_stock'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->visible(fn (): bool => $this->mayEdit())
            ->schema([
                TextInput::make('stock_qty')
                    ->label(__('admin_variants.variants.columns.stock'))
                    ->helperText(__('admin_variants.variants.stock_hint'))
                    ->numeric()->integer()->minValue(0)->maxValue(2_000_000_000),
            ])
            ->chunkSelectedRecords(VariantService::CHUNK_SIZE)
            ->deselectRecordsAfterCompletion()
            ->action(function (Enumerable $records, array $data): void {
                abort_unless($this->mayEdit(), 403);

                try {
                    $count = app(VariantService::class)->bulkSetStock($this->ids($records), filled($data['stock_qty'] ?? null) ? (int) $data['stock_qty'] : null);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_variants.bulk.done', ['count' => $count]))->send();
            });
    }

    private function setPriceAction(): BulkAction
    {
        return BulkAction::make('set_price')
            ->label(__('admin_variants.bulk.set_price'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (): bool => $this->mayEdit())
            ->schema([
                Select::make('field')
                    ->label(__('admin_variants.bulk.price_field'))
                    ->options(collect(VariantService::PRICE_FIELDS)->mapWithKeys(fn (string $field): array => [$field => __('variants.fields.'.$field)])->all())
                    ->default('base_price')
                    ->required()
                    ->native(false)
                    ->selectablePlaceholder(false),
                TextInput::make('price')
                    ->label(__('admin_variants.bulk.price'))
                    ->helperText(__('admin_variants.bulk.price_hint'))
                    ->inputMode('decimal')
                    ->rules([new RublesAmount])
                    ->maxLength(20),
            ])
            ->chunkSelectedRecords(VariantService::CHUNK_SIZE)
            ->deselectRecordsAfterCompletion()
            ->action(function (Enumerable $records, array $data): void {
                abort_unless($this->mayEdit(), 403);

                try {
                    $count = app(VariantService::class)->bulkSetPrice($this->ids($records), (string) $data['field'], filled($data['price'] ?? null) ? (string) $data['price'] : null);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_variants.bulk.done', ['count' => $count]))->send();
            });
    }

    private function activeAction(string $name, bool $active): BulkAction
    {
        return BulkAction::make($name)
            ->label(__('admin_variants.bulk.'.$name))
            ->icon($active ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
            ->visible(fn (): bool => $this->mayEdit())
            ->requiresConfirmation()
            ->chunkSelectedRecords(VariantService::CHUNK_SIZE)
            ->deselectRecordsAfterCompletion()
            ->action(function (Enumerable $records) use ($active): void {
                abort_unless($this->mayEdit(), 403);

                $count = app(VariantService::class)->bulkSetActive($this->ids($records), $active);

                Notification::make()->success()->title(__('admin_variants.bulk.done', ['count' => $count]))->send();
            });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  Enumerable<int, Model>  $records
     * @return list<int>
     */
    private function ids(Enumerable $records): array
    {
        return $records->map(fn (Model $record): int => (int) $record->getKey())->values()->all();
    }

    /**
     * One required select per axis.
     *
     * @return list<Select>
     */
    private function axisSelects(): array
    {
        $selects = [];

        foreach ($this->axes() as $axis) {
            $selects[] = Select::make('axis_'.$axis->id)
                ->label($axis->name)
                ->options(fn (): array => $this->valueOptions($axis->id))
                ->required()
                ->searchable()
                ->native(false);
        }

        return $selects;
    }

    /**
     * @return array<string, int>
     */
    private function currentValues(ProductVariant $variant): array
    {
        $state = [];

        foreach ($variant->values()->get() as $value) {
            $state['axis_'.$value->option_type_id] = $value->option_value_id;
        }

        return $state;
    }

    /**
     * @return array<int, string>
     */
    private function valueOptions(int $typeId): array
    {
        return OptionValue::query()->where('option_type_id', $typeId)->orderBy('sort_order')->orderBy('id')->limit(500)->pluck('value', 'id')->all();
    }

    /**
     * @return list<OptionType>
     */
    private function axes(): array
    {
        return app(VariantService::class)->axes($this->product())->all();
    }

    private function label(ProductVariant $variant): string
    {
        $this->labels ??= app(VariantLabel::class)->forMany(iterator_to_array($this->getTableRecords(), false));

        return $this->labels[$variant->id] ?? app(VariantLabel::class)->for($variant);
    }

    private function product(): Product
    {
        /** @var Product $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }

    private function mayEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'products.update');
    }
}
