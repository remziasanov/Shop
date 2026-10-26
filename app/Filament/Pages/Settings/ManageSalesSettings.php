<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Enums\SalesMode;
use App\Enums\StockDisplay;
use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Rules\RublesAmount;
use App\Settings\SalesSettings;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageSalesSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = SalesSettings::class;

    protected static string $translationKey = 'sales';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 30;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('shop.settings.sales.sections.mode'))->schema([
                Select::make('default_mode')
                    ->label(__('shop.settings.sales.fields.default_mode'))
                    ->options(SalesMode::options())
                    ->required()
                    ->native(false),
                Toggle::make('stock_tracking')
                    ->label(__('shop.settings.sales.fields.stock_tracking')),
                Toggle::make('allow_backorder')
                    ->label(__('shop.settings.sales.fields.allow_backorder')),
                Select::make('stock_display')
                    ->label(__('shop.settings.sales.fields.stock_display'))
                    ->options(StockDisplay::options())
                    ->required()
                    ->native(false),
                TextInput::make('low_stock_threshold')
                    ->label(__('shop.settings.sales.fields.low_stock_threshold'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required(),
                TextInput::make('reservation_hours')
                    ->label(__('shop.settings.sales.fields.reservation_hours'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(8760)
                    ->required(),
                TextInput::make('min_order_amount')
                    ->label(__('shop.settings.sales.fields.min_order_amount'))
                    ->inputMode('decimal')
                    ->rule(new RublesAmount)
                    ->suffix('₽')
                    ->required(),
            ])->columns(2),
            Section::make(__('shop.settings.sales.sections.delivery'))->schema([
                Repeater::make('delivery_methods')
                    ->hiddenLabel()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('shop.settings.sales.fields.method_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('description')
                            ->label(__('shop.settings.sales.fields.method_description'))
                            ->maxLength(1000),
                        TextInput::make('price')
                            ->label(__('shop.settings.sales.fields.delivery_price'))
                            ->inputMode('decimal')
                            ->rule(new RublesAmount)
                            ->suffix('₽')
                            ->required(),
                        TextInput::make('free_from')
                            ->label(__('shop.settings.sales.fields.free_from'))
                            ->inputMode('decimal')
                            ->rule(new RublesAmount)
                            ->suffix('₽'),
                        Toggle::make('requires_address')
                            ->label(__('shop.settings.sales.fields.requires_address')),
                        Toggle::make('is_active')
                            ->label(__('shop.settings.sales.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('shop.settings.add'))
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->collapsible(),
            ]),
            Section::make(__('shop.settings.sales.sections.payment'))->schema([
                Repeater::make('payment_methods')
                    ->hiddenLabel()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('shop.settings.sales.fields.method_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('description')
                            ->label(__('shop.settings.sales.fields.method_description'))
                            ->maxLength(1000),
                        Toggle::make('is_active')
                            ->label(__('shop.settings.sales.fields.is_active'))
                            ->default(true),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('shop.settings.add'))
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->collapsible(),
            ]),
            Section::make(__('shop.settings.sales.sections.numbering'))->schema([
                TextInput::make('order_prefix')
                    ->label(__('shop.settings.sales.fields.order_prefix'))
                    ->required()
                    ->maxLength(5)
                    ->alphaNum(),
                TextInput::make('reservation_prefix')
                    ->label(__('shop.settings.sales.fields.reservation_prefix'))
                    ->required()
                    ->maxLength(5)
                    ->alphaNum(),
                TextInput::make('inquiry_prefix')
                    ->label(__('shop.settings.sales.fields.inquiry_prefix'))
                    ->required()
                    ->maxLength(5)
                    ->alphaNum(),
            ])->columns(3),
            Section::make(__('shop.settings.sales.sections.thanks'))->schema([
                Textarea::make('thanks_order_text')
                    ->label(__('shop.settings.sales.fields.thanks_order_text'))
                    ->rows(2)
                    ->maxLength(2000),
                Textarea::make('thanks_reservation_text')
                    ->label(__('shop.settings.sales.fields.thanks_reservation_text'))
                    ->rows(2)
                    ->maxLength(2000),
                Textarea::make('thanks_inquiry_text')
                    ->label(__('shop.settings.sales.fields.thanks_inquiry_text'))
                    ->rows(2)
                    ->maxLength(2000),
            ]),
        ])->columns(1);
    }

    /**
     * Settings keep money in kopecks (D-009); the form works in rubles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['default_mode'] = $this->enumValue($data['default_mode']);
        $data['stock_display'] = $this->enumValue($data['stock_display']);
        $data['min_order_amount'] = Money::toRubles((int) $data['min_order_amount']);

        $data['delivery_methods'] = array_map(function (array $method): array {
            $method['price'] = Money::toRubles((int) $method['price']);
            $method['free_from'] = isset($method['free_from']) ? Money::toRubles((int) $method['free_from']) : null;

            return $method;
        }, $data['delivery_methods']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_mode'] = SalesMode::from((string) $data['default_mode']);
        $data['stock_display'] = StockDisplay::from((string) $data['stock_display']);
        $data['low_stock_threshold'] = (int) $data['low_stock_threshold'];
        $data['reservation_hours'] = (int) $data['reservation_hours'];
        $data['min_order_amount'] = Money::fromRubles($data['min_order_amount']);

        $data['delivery_methods'] = array_values(array_map(function (array $method): array {
            $method['price'] = Money::fromRubles($method['price']);
            $method['free_from'] = filled($method['free_from'] ?? null) ? Money::fromRubles($method['free_from']) : null;
            $method['description'] = filled($method['description'] ?? null) ? $method['description'] : null;
            $method['requires_address'] = (bool) ($method['requires_address'] ?? false);
            $method['is_active'] = (bool) ($method['is_active'] ?? false);

            return $method;
        }, $data['delivery_methods'] ?? []));

        $data['payment_methods'] = array_values(array_map(function (array $method): array {
            $method['description'] = filled($method['description'] ?? null) ? $method['description'] : null;
            $method['is_active'] = (bool) ($method['is_active'] ?? false);

            return $method;
        }, $data['payment_methods'] ?? []));

        return $data;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
