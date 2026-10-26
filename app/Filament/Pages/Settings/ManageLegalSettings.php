<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Settings\LegalSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageLegalSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = LegalSettings::class;

    protected static string $translationKey = 'legal';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?int $navigationSort = 70;

    public function form(Schema $schema): Schema
    {
        // Privacy policy / consent page selects arrive with the Pages resource (stage 5).
        return $schema->components([
            Textarea::make('seller_details')
                ->label(__('shop.settings.legal.fields.seller_details'))
                ->rows(4)
                ->maxLength(2000)
                ->columnSpanFull(),
            Textarea::make('consent_text')
                ->label(__('shop.settings.legal.fields.consent_text'))
                ->helperText(__('shop.settings.legal.hints.check_with_lawyer'))
                ->required()
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
            Toggle::make('cookie_banner_enabled')
                ->label(__('shop.settings.legal.fields.cookie_banner_enabled'))
                ->columnSpanFull(),
            Textarea::make('cookie_banner_text')
                ->label(__('shop.settings.legal.fields.cookie_banner_text'))
                ->required()
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['seller_details'] = filled($data['seller_details'] ?? null) ? $data['seller_details'] : null;

        return $data;
    }
}
