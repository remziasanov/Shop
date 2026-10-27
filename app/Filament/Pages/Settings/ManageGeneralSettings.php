<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Filament\Concerns\DeletesReplacedSettingFiles;
use App\Settings\GeneralSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageGeneralSettings extends SettingsPage
{
    use AdminOnlySettingsPage;
    use DeletesReplacedSettingFiles;

    protected static string $settings = GeneralSettings::class;

    protected static string $translationKey = 'general';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 10;

    /**
     * @return list<string>
     */
    protected function settingsFileFields(): array
    {
        return ['logo', 'favicon'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('shop.settings.general.sections.shop'))->schema([
                TextInput::make('shop_name')
                    ->label(__('shop.settings.general.fields.shop_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('tagline')
                    ->label(__('shop.settings.general.fields.tagline'))
                    ->maxLength(255),
                FileUpload::make('logo')
                    ->label(__('shop.settings.general.fields.logo'))
                    ->disk('public')
                    ->directory('settings')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(2048),
                FileUpload::make('favicon')
                    ->label(__('shop.settings.general.fields.favicon'))
                    ->disk('public')
                    ->directory('settings')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'])
                    ->maxSize(512),
            ])->columns(2),
            Section::make(__('shop.settings.general.sections.contacts'))->schema([
                Repeater::make('phones')
                    ->label(__('shop.settings.general.fields.phones'))
                    ->simple(
                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->maxLength(32),
                    )
                    ->addActionLabel(__('shop.settings.add'))
                    ->defaultItems(0),
                TextInput::make('email')
                    ->label(__('shop.settings.general.fields.email'))
                    ->email()
                    ->maxLength(255),
                Textarea::make('address')
                    ->label(__('shop.settings.general.fields.address'))
                    ->rows(2)
                    ->maxLength(1000),
                Textarea::make('working_hours')
                    ->label(__('shop.settings.general.fields.working_hours'))
                    ->rows(2)
                    ->maxLength(1000),
                Repeater::make('social_links')
                    ->label(__('shop.settings.general.fields.social_links'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('shop.settings.general.fields.social_title'))
                            ->required()
                            ->maxLength(100),
                        TextInput::make('url')
                            ->label(__('shop.settings.general.fields.social_url'))
                            ->url()
                            ->required()
                            ->maxLength(500),
                    ])
                    ->columns(2)
                    ->addActionLabel(__('shop.settings.add'))
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ])->columns(2),
            Section::make(__('shop.settings.general.sections.footer'))->schema([
                Textarea::make('footer_text')
                    ->label(__('shop.settings.general.fields.footer_text'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]),
            Section::make(__('shop.settings.general.sections.status'))->schema([
                Toggle::make('shop_open')
                    ->label(__('shop.settings.general.fields.shop_open')),
                Textarea::make('closed_message')
                    ->label(__('shop.settings.general.fields.closed_message'))
                    ->rows(2)
                    ->maxLength(1000),
            ])->columns(2),
        ])->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['phones'] = array_values($data['phones'] ?? []);
        $data['social_links'] = array_values($data['social_links'] ?? []);

        return $data;
    }
}
