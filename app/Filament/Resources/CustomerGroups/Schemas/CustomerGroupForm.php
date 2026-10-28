<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerGroups\Schemas;

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\StorefrontSection;
use App\Models\CustomerGroup;
use App\Rules\RublesAmount;
use App\Services\Catalog\CategoryTree;
use App\Services\Sales\MethodCodes;
use App\Settings\SalesSettings;
use App\Support\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Customer group form (CG-18): tabs «Основное», «Разделы и категории», «Цены», «Оформление». Fields that act in later
 * stages (discount, retail price, order only after login, documents) are added by those stages (D-085).
 *
 * The «allowed» lists work as checkboxes with everything checked by default; a fully checked list is stored as
 * `null` (= no restriction, also for methods added later).
 */
final class CustomerGroupForm
{
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    private const MODES = ['cart', 'reservation', 'inquiry'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('group')->tabs([
                Tab::make(__('customer_groups.tabs.main'))->schema([
                    TextInput::make('name')
                        ->label(__('customer_groups.fields.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->label(__('customer_groups.fields.code'))
                        ->helperText(__('customer_groups.fields.code_hint'))
                        ->required()
                        ->maxLength(32)
                        ->regex(self::CODE_PATTERN)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?CustomerGroup $record): bool => $record !== null && $record->is_system)
                        ->dehydrated(fn (?CustomerGroup $record): bool => $record === null || ! $record->is_system),
                    Textarea::make('description')
                        ->label(__('customer_groups.fields.description'))
                        ->maxLength(500)
                        ->rows(2)
                        ->columnSpanFull(),
                    TextInput::make('sort_order')
                        ->label(__('customer_groups.fields.sort_order'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(1_000_000)
                        ->default(100)
                        ->required(),
                    Toggle::make('is_active')
                        ->label(__('customer_groups.fields.is_active'))
                        ->default(true)
                        ->disabled(fn (?CustomerGroup $record): bool => $record !== null && $record->is_system)
                        ->dehydrated(fn (?CustomerGroup $record): bool => $record === null || ! $record->is_system),
                ])->columns(2),

                Tab::make(__('customer_groups.tabs.access'))->schema([
                    CheckboxList::make('visible_sections')
                        ->label(__('customer_groups.fields.visible_sections'))
                        ->helperText(__('customer_groups.fields.visible_sections_hint'))
                        ->options(StorefrontSection::options())
                        ->default(StorefrontSection::defaults())
                        ->columns(2)
                        ->columnSpanFull(),
                    Select::make('category_access_mode')
                        ->label(__('customer_groups.fields.category_access_mode'))
                        ->options(CategoryAccessMode::options())
                        ->default(CategoryAccessMode::All->value)
                        ->required()
                        ->live()
                        ->native(false),
                    Select::make('category_ids')
                        ->label(__('customer_groups.fields.categories'))
                        ->helperText(__('customer_groups.fields.categories_hint'))
                        ->options(fn (): array => app(CategoryTree::class)->options())
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => self::mode($get) !== CategoryAccessMode::All)
                        ->required(fn (Get $get): bool => self::mode($get) === CategoryAccessMode::Only)
                        ->columnSpanFull(),
                ])->columns(2),

                Tab::make(__('customer_groups.tabs.prices'))->schema([
                    Select::make('price_display')
                        ->label(__('customer_groups.fields.price_display'))
                        ->helperText(__('customer_groups.fields.price_display_hint'))
                        ->options(fn (?CustomerGroup $record): array => self::priceDisplayOptions($record))
                        ->default(PriceDisplayMode::Show->value)
                        ->required()
                        ->native(false),
                    Select::make('stock_display')
                        ->label(__('customer_groups.fields.stock_display'))
                        ->options(GroupStockDisplay::options())
                        ->default(GroupStockDisplay::Inherit->value)
                        ->required()
                        ->native(false),
                ])->columns(2),

                Tab::make(__('customer_groups.tabs.checkout'))->schema([
                    Section::make(__('customer_groups.fields.allowed_sales_modes'))
                        ->description(__('customer_groups.fields.allowed_hint'))
                        ->schema([
                            CheckboxList::make('allowed_sales_modes')
                                ->hiddenLabel()
                                ->default(self::MODES)
                                ->options([
                                    'cart' => __('customer_groups.modes.cart'),
                                    'reservation' => __('customer_groups.modes.reservation'),
                                    'inquiry' => __('customer_groups.modes.inquiry'),
                                ]),
                        ]),
                    TextInput::make('min_order_amount')
                        ->label(__('customer_groups.fields.min_order_amount'))
                        ->helperText(__('customer_groups.fields.min_order_amount_hint'))
                        ->inputMode('decimal')
                        ->rule(new RublesAmount)
                        ->suffix('₽'),
                    Section::make(__('customer_groups.fields.allowed_delivery_codes'))
                        ->description(__('customer_groups.fields.allowed_hint'))
                        ->schema([
                            CheckboxList::make('allowed_delivery_codes')
                                ->hiddenLabel()
                                ->default(fn (): array => array_keys(self::methodOptions('delivery_methods')))
                                ->options(fn (): array => self::methodOptions('delivery_methods')),
                        ]),
                    Section::make(__('customer_groups.fields.allowed_payment_codes'))
                        ->description(__('customer_groups.fields.allowed_hint'))
                        ->schema([
                            CheckboxList::make('allowed_payment_codes')
                                ->hiddenLabel()
                                ->default(fn (): array => array_keys(self::methodOptions('payment_methods')))
                                ->options(fn (): array => self::methodOptions('payment_methods')),
                        ]),
                ])->columns(2),
            ])->columnSpanFull(),
        ]);
    }

    /**
     * Form state of an existing group: unrestricted lists are shown fully checked.
     *
     * @return array<string, mixed>
     */
    public static function stateFor(CustomerGroup $group): array
    {
        return [
            'category_ids' => $group->categories()->pluck('categories.id')->map(fn (mixed $id): int => (int) $id)->all(),
            'allowed_sales_modes' => $group->allowed_sales_modes ?? self::MODES,
            'allowed_delivery_codes' => $group->allowed_delivery_codes ?? array_keys(self::methodOptions('delivery_methods')),
            'allowed_payment_codes' => $group->allowed_payment_codes ?? array_keys(self::methodOptions('payment_methods')),
            'min_order_amount' => $group->min_order_amount,
            'category_access_mode' => $group->category_access_mode->value,
            'price_display' => $group->price_display->value,
            'stock_display' => $group->stock_display->value,
        ];
    }

    /**
     * Splits the submitted state into the attributes of the group and the selected category ids.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    public static function split(array $data): array
    {
        $categoryIds = array_values(array_map('intval', (array) ($data['category_ids'] ?? [])));
        unset($data['category_ids']);

        $data['visible_sections'] = array_values(array_intersect(array_keys(StorefrontSection::options()), (array) ($data['visible_sections'] ?? [])));
        $data['allowed_sales_modes'] = self::listOrNull($data['allowed_sales_modes'] ?? [], self::MODES);
        $data['allowed_delivery_codes'] = self::listOrNull($data['allowed_delivery_codes'] ?? [], array_keys(self::methodOptions('delivery_methods')));
        $data['allowed_payment_codes'] = self::listOrNull($data['allowed_payment_codes'] ?? [], array_keys(self::methodOptions('payment_methods')));
        $data['min_order_amount'] = filled($data['min_order_amount'] ?? null) ? Money::toRubles(Money::fromRubles($data['min_order_amount'])) : null;

        return [$data, $categoryIds];
    }

    /**
     * A fully checked list is "no restriction" (null); a partial or empty one is stored as it is.
     *
     * @param  list<string>  $all
     * @return list<string>|null
     */
    private static function listOrNull(mixed $selected, array $all): ?array
    {
        $selected = array_values(array_map('strval', (array) $selected));

        return $all !== [] && array_diff($all, $selected) === [] ? null : $selected;
    }

    /**
     * @return array<string, string>
     */
    private static function priceDisplayOptions(?CustomerGroup $record): array
    {
        // «login» needs customer accounts: it is offered from stage 9, but a group that already has it keeps it.
        $options = [
            PriceDisplayMode::Show->value => PriceDisplayMode::Show->label(),
            PriceDisplayMode::Request->value => PriceDisplayMode::Request->label(),
        ];

        if ($record !== null && $record->price_display === PriceDisplayMode::Login) {
            $options[PriceDisplayMode::Login->value] = PriceDisplayMode::Login->label();
        }

        return $options;
    }

    /**
     * Code => name of the delivery or payment methods of the sales settings.
     *
     * @return array<string, string>
     */
    private static function methodOptions(string $property): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(app(SalesSettings::class)->{$property});
        $codes = MethodCodes::codesOf($rows);
        $options = [];

        foreach ($rows as $index => $row) {
            $options[$codes[$index]] = (string) ($row['name'] ?? $codes[$index]).(($row['is_active'] ?? false) ? '' : ' ('.__('customer_groups.fields.method_inactive').')');
        }

        return $options;
    }

    private static function mode(Get $get): ?CategoryAccessMode
    {
        $value = $get('category_access_mode');

        return $value instanceof CategoryAccessMode ? $value : CategoryAccessMode::tryFrom((string) $value);
    }
}
