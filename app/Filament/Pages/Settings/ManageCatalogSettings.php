<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Settings\CatalogSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → «Каталог и фильтры» (FL-05, FL-11, FL-14, FL-16). `attributes_migrated_at` (FL-04) is a flag of the
 * migration, not a field of the form: it is neither shown nor saved from here.
 */
class ManageCatalogSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = CatalogSettings::class;

    protected static string $translationKey = 'catalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static ?int $navigationSort = 90;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('shop.settings.catalog.sections.filters'))->schema([
                TextInput::make('filters_max_per_category')
                    ->label(__('shop.settings.catalog.fields.filters_max_per_category'))
                    ->helperText(__('shop.settings.catalog.hints.filters_max_per_category'))
                    ->numeric()->integer()->minValue(1)->maxValue(9)->required(),
                TextInput::make('facets_cache_minutes')
                    ->label(__('shop.settings.catalog.fields.facets_cache_minutes'))
                    ->helperText(__('shop.settings.catalog.hints.facets_cache_minutes'))
                    ->numeric()->integer()->minValue(1)->maxValue(1440)->required(),
            ])->columns(2),
            Section::make(__('shop.settings.catalog.sections.seo'))->schema([
                TextInput::make('seo_single_value_min_products')
                    ->label(__('shop.settings.catalog.fields.seo_single_value_min_products'))
                    ->helperText(__('shop.settings.catalog.hints.seo_single_value_min_products'))
                    ->numeric()->integer()->minValue(1)->maxValue(1000)->required(),
            ]),
            Section::make(__('shop.settings.catalog.sections.import'))->schema([
                Toggle::make('create_attributes_on_import')
                    ->label(__('shop.settings.catalog.fields.create_attributes_on_import'))
                    ->helperText(__('shop.settings.catalog.hints.create_attributes_on_import')),
            ]),
        ])->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['attributes_migrated_at']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['attributes_migrated_at']);

        return $data;
    }
}
