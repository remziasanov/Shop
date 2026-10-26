<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\SalesMode;
use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\Product;
use App\Rules\RublesAmount;
use App\Services\Catalog\CategoryTree;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Sales\SalesModeResolver;
use App\Support\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use InvalidArgumentException;

/**
 * Product form with tabs (TZ 6.6).
 *
 * The characteristics list is a Repeater (not KeyValue): it keeps order and allows repeated names.
 * Its state key is `specs`; the pages map it to the model's `attributes` column.
 */
final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('product')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::mainTab(),
                    self::priceTab(),
                    self::stockTab(),
                    self::salesTab(),
                    self::descriptionTab(),
                    self::attributesTab(),
                    self::photosTab(),
                    self::seoTab(),
                ]),
        ]);
    }

    private static function mainTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.main'))
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('admin_products.fields.name'))
                        ->required()
                        ->maxLength(500),
                    // Unique among all rows, trashed ones included (the DB index does not ignore them).
                    TextInput::make('sku')
                        ->label(__('admin_products.fields.sku'))
                        ->required()
                        ->maxLength(100)
                        ->unique(table: 'products', column: 'sku', ignoreRecord: true),
                    TextInput::make('slug')
                        ->label(__('admin_products.fields.slug'))
                        ->helperText(__('admin_products.fields.slug_hint'))
                        ->maxLength(255),
                    Select::make('category_id')
                        ->label(__('admin_products.fields.category'))
                        ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->live(),
                    TextInput::make('brand')
                        ->label(__('admin_products.fields.brand'))
                        ->maxLength(255)
                        ->datalist(fn (): array => self::brands()),
                    TextInput::make('sort_order')
                        ->label(__('admin_products.fields.sort_order'))
                        ->numeric()
                        ->integer()
                        ->default(0),
                    Toggle::make('is_active')
                        ->label(__('admin_products.fields.is_active'))
                        ->default(true),
                    Toggle::make('is_featured')
                        ->label(__('admin_products.fields.is_featured'))
                        ->default(false),
                    Toggle::make('is_new')
                        ->label(__('admin_products.fields.is_new'))
                        ->default(false),
                ]),
            ]);
    }

    private static function priceTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.price'))
            ->schema([
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Grid::make(2)->columnSpan(['lg' => 2])->schema([
                        self::moneyInput('base_price')->required()->default('0.00'),
                        self::moneyInput('cost_price'),
                        self::moneyInput('compare_at_price'),
                        self::moneyInput('fixed_price')
                            ->helperText(__('admin_products.fields.fixed_price_hint')),
                        Toggle::make('price_on_request')
                            ->label(__('admin_products.fields.price_on_request'))
                            ->live()
                            ->default(false),
                    ]),
                    Section::make(__('admin_products.price.final_block'))
                        ->schema([
                            Text::make(fn (Get $get): string => self::finalPriceSummary($get))->weight('bold'),
                            Text::make(fn (Get $get): string => self::oldPriceSummary($get)),
                            Text::make(__('admin_products.price.rules_none'))->color('gray'),
                        ]),
                ]),
            ]);
    }

    private static function stockTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.stock'))
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('stock_qty')
                        ->label(__('admin_products.fields.stock_qty'))
                        ->helperText(__('admin_products.fields.stock_qty_hint'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(2_000_000_000)
                        ->live(onBlur: true),
                    TextInput::make('unit')
                        ->label(__('admin_products.fields.unit'))
                        ->default('шт')
                        ->required()
                        ->maxLength(20),
                    TextInput::make('reserved_qty')
                        ->label(__('admin_products.fields.reserved_qty'))
                        ->default(0)
                        ->disabled()
                        ->dehydrated(false),
                    Section::make(__('admin_products.fields.available_qty'))
                        ->schema([
                            Text::make(function (Get $get): string {
                                $stock = $get('stock_qty');

                                if ($stock === null || $stock === '') {
                                    return __('admin_products.table.stock_untracked');
                                }

                                return (string) ((int) $stock - (int) ($get('reserved_qty') ?? 0));
                            }),
                        ]),
                ]),
            ]);
    }

    private static function salesTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.sales'))
            ->schema([
                Select::make('sales_mode')
                    ->label(__('admin_products.fields.sales_mode'))
                    ->options(SalesMode::options())
                    ->placeholder(__('admin_products.fields.sales_mode_inherit'))
                    ->native(false)
                    ->live(),
                Text::make(function (Get $get): string {
                    $category = $get('category_id');
                    $mode = SalesMode::tryFrom((string) ($get('sales_mode') instanceof SalesMode ? $get('sales_mode')->value : $get('sales_mode')));

                    $resolved = app(SalesModeResolver::class)->resolveFor(
                        $mode,
                        filled($category) ? (int) $category : null,
                        (bool) $get('price_on_request'),
                    );

                    return __('admin_products.sales.effective', ['mode' => $resolved->label()]);
                }),
            ]);
    }

    private static function descriptionTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.description'))
            ->schema([
                Textarea::make('short_description')
                    ->label(__('admin_products.fields.short_description'))
                    ->maxLength(500)
                    ->rows(4),
                // No file attachments: the editor only offers the formatting the HtmlSanitizer keeps.
                RichEditor::make('description')
                    ->label(__('admin_products.fields.description'))
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike', 'link'],
                        ['h2', 'h3'],
                        ['blockquote', 'bulletList', 'orderedList'],
                        ['undo', 'redo'],
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function attributesTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.attributes'))
            ->schema([
                Repeater::make('specs')
                    ->label(__('admin_products.fields.attributes'))
                    ->helperText(__('admin_products.fields.attributes_hint'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('admin_products.fields.attribute_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('value')
                            ->label(__('admin_products.fields.attribute_value'))
                            ->maxLength(1000),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->defaultItems(0)
                    ->addActionLabel(__('admin_products.fields.attribute_add'))
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null),
            ]);
    }

    private static function photosTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.photos'))
            ->schema([
                // Create: photos are uploaded with the form and processed right after the product is saved.
                PhotoUpload::make()
                    ->visibleOn('create'),
                // Edit: the "Фото" relation manager below the form manages the existing photos.
                Text::make(__('admin_products.photos.manage_below'))
                    ->visibleOn('edit'),
            ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make(__('admin_products.tabs.seo'))
            ->schema([
                TextInput::make('meta_title')
                    ->label(__('admin_products.fields.meta_title'))
                    ->maxLength(255)
                    ->live(onBlur: true),
                Textarea::make('meta_description')
                    ->label(__('admin_products.fields.meta_description'))
                    ->maxLength(255)
                    ->rows(3)
                    ->live(onBlur: true),
                Section::make(__('admin_products.seo.preview'))
                    ->schema([
                        Text::make(fn (Get $get): string => (string) (filled($get('meta_title')) ? $get('meta_title') : $get('name')))
                            ->weight('bold')
                            ->color('primary'),
                        Text::make(fn (Get $get): string => url('/product/'.(filled($get('slug')) ? $get('slug') : '…')))
                            ->color('success'),
                        Text::make(fn (Get $get): string => (string) (filled($get('meta_description')) ? $get('meta_description') : $get('short_description')))
                            ->color('gray'),
                    ]),
            ]);
    }

    /**
     * Rubles input stored as a "1290.50" string; invalid input fails validation, no floats involved.
     */
    private static function moneyInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->label(__('admin_products.fields.'.$name))
            ->rule(new RublesAmount)
            ->inputMode('decimal')
            ->maxLength(20)
            ->live(onBlur: true)
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Money::toRubles(Money::fromRubles($state)) : null);
    }

    private static function finalPriceSummary(Get $get): string
    {
        if ($get('price_on_request')) {
            return __('admin_products.price.on_request');
        }

        $prices = self::preview($get);

        return $prices === null
            ? __('admin_products.price.invalid')
            : __('admin_products.price.final', ['price' => Money::format(Money::fromRubles($prices['final_price']))]);
    }

    private static function oldPriceSummary(Get $get): string
    {
        $prices = self::preview($get);

        if ($prices === null || $get('price_on_request')) {
            return '';
        }

        return $prices['display_old_price'] === null
            ? __('admin_products.price.no_old')
            : __('admin_products.price.old', ['price' => Money::format(Money::fromRubles($prices['display_old_price']))]);
    }

    /**
     * @return array{final_price: string, display_old_price: string|null}|null null when an input is not a valid amount
     */
    private static function preview(Get $get): ?array
    {
        $value = static fn (string $key): ?string => filled($get($key)) ? (string) $get($key) : null;

        try {
            return app(ProductPriceUpdater::class)->calculate($value('base_price'), $value('fixed_price'), $value('compare_at_price'));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private static function brands(): array
    {
        return Product::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->limit(500)
            ->pluck('brand')
            ->all();
    }
}
