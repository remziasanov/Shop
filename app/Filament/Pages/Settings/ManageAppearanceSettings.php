<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Settings\AppearanceSettings;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageAppearanceSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = AppearanceSettings::class;

    protected static string $translationKey = 'appearance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?int $navigationSort = 20;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ColorPicker::make('primary_color')
                ->label(__('shop.settings.appearance.fields.primary_color'))
                ->required(),
            ColorPicker::make('accent_color')
                ->label(__('shop.settings.appearance.fields.accent_color'))
                ->required(),
            Select::make('grid_columns')
                ->label(__('shop.settings.appearance.fields.grid_columns'))
                ->options([3 => '3', 4 => '4'])
                ->required()
                ->native(false),
            TextInput::make('per_page')
                ->label(__('shop.settings.appearance.fields.per_page'))
                ->numeric()
                ->integer()
                ->minValue(4)
                ->maxValue(100)
                ->required(),
            FileUpload::make('placeholder_image')
                ->label(__('shop.settings.appearance.fields.placeholder_image'))
                ->disk('public')
                ->directory('settings')
                ->visibility('public')
                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                ->maxSize(2048)
                ->image()
                ->columnSpanFull(),
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['grid_columns'] = (int) $data['grid_columns'];
        $data['per_page'] = (int) $data['per_page'];

        return $data;
    }
}
