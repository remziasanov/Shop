<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Settings\PricingSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManagePricingSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = PricingSettings::class;

    protected static string $translationKey = 'pricing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 40;

    /** Settings that change the calculated price (the show_* toggles only affect the storefront output). */
    private const PRICE_FIELDS = ['rounding_step', 'rounding_direction', 'rounding_scope', 'psychological_price', 'group_prices_rounding'];

    /** @var array<string, mixed> */
    private array $priceFieldsBefore = [];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('rounding_step')
                ->label(__('shop.settings.pricing.fields.rounding_step'))
                ->options(RoundingStep::options())
                ->required()
                ->native(false),
            Select::make('rounding_direction')
                ->label(__('shop.settings.pricing.fields.rounding_direction'))
                ->options(RoundingDirection::options())
                ->required()
                ->native(false),
            Select::make('rounding_scope')
                ->label(__('shop.settings.pricing.fields.rounding_scope'))
                ->options(RoundingScope::options())
                ->required()
                ->native(false),
            Toggle::make('psychological_price')
                ->label(__('shop.settings.pricing.fields.psychological_price'))
                ->helperText(__('shop.settings.pricing.hints.psychological_price')),
            Toggle::make('show_old_price')
                ->label(__('shop.settings.pricing.fields.show_old_price')),
            Toggle::make('show_discount_percent')
                ->label(__('shop.settings.pricing.fields.show_discount_percent')),
            // GP-19: customer group prices.
            Section::make(__('admin_group_prices.settings.section'))
                ->schema([
                    Toggle::make('group_prices_rounding')
                        ->label(__('admin_group_prices.settings.group_prices_rounding'))
                        ->helperText(__('admin_group_prices.settings.group_prices_rounding_hint')),
                    Toggle::make('tier_hint_enabled')
                        ->label(__('admin_group_prices.settings.tier_hint_enabled'))
                        ->helperText(__('admin_group_prices.settings.tier_hint_enabled_hint'))
                        ->live(),
                    TextInput::make('tier_hint_percent')
                        ->label(__('admin_group_prices.settings.tier_hint_percent'))
                        ->helperText(__('admin_group_prices.settings.tier_hint_percent_hint'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->suffix('%')
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('tier_hint_enabled')),
                    TextInput::make('tier_hint_qty')
                        ->label(__('admin_group_prices.settings.tier_hint_qty'))
                        ->helperText(__('admin_group_prices.settings.tier_hint_qty_hint'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100000)
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('tier_hint_enabled')),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['rounding_step', 'rounding_direction', 'rounding_scope'] as $key) {
            $data[$key] = (string) ($data[$key] instanceof BackedEnum ? $data[$key]->value : $data[$key]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['rounding_step'] = RoundingStep::from((int) $data['rounding_step']);
        $data['rounding_direction'] = RoundingDirection::from((string) $data['rounding_direction']);
        $data['rounding_scope'] = RoundingScope::from((string) $data['rounding_scope']);

        return $data;
    }

    protected function beforeSave(): void
    {
        $settings = app(PricingSettings::class);

        $this->priceFieldsBefore = [];

        foreach (self::PRICE_FIELDS as $field) {
            $this->priceFieldsBefore[$field] = $settings->{$field};
        }
    }

    /**
     * New rounding settings apply to every product at once (TZ 6.3): queue the full recalculation, like
     * `shop:recalc-prices --all`. The queue dispatch waits for the settings transaction to commit.
     */
    protected function afterSave(): void
    {
        $settings = app(PricingSettings::class);

        foreach (self::PRICE_FIELDS as $field) {
            if ($settings->{$field} !== $this->priceFieldsBefore[$field]) {
                app(PriceRuleRecalculator::class)->dispatchAll();

                Notification::make()->success()->title(__('admin_pricing.settings.recalculating'))->send();

                return;
            }
        }
    }
}
