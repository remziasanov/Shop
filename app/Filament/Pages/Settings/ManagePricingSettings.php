<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Settings\PricingSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManagePricingSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = PricingSettings::class;

    protected static string $translationKey = 'pricing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?int $navigationSort = 40;

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
}
