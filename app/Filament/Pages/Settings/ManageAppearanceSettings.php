<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Filament\Concerns\DeletesReplacedSettingFiles;
use App\Filament\Pages\Settings\Sections\PwaSettingsSection;
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
    use DeletesReplacedSettingFiles {
        afterSave as protected deleteReplacedSettingFiles;
    }

    private const HEX_COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    protected static string $settings = AppearanceSettings::class;

    protected static string $translationKey = 'appearance';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?int $navigationSort = 20;

    /**
     * @return list<string>
     */
    protected function settingsFileFields(): array
    {
        return ['placeholder_image', 'pwa_icon'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ColorPicker::make('primary_color')
                ->label(__('shop.settings.appearance.fields.primary_color'))
                ->required()
                ->regex(self::HEX_COLOR_PATTERN)
                ->validationMessages(['regex' => __('shop.settings.appearance.errors.color_format')]),
            ColorPicker::make('accent_color')
                ->label(__('shop.settings.appearance.fields.accent_color'))
                ->required()
                ->regex(self::HEX_COLOR_PATTERN)
                ->validationMessages(['regex' => __('shop.settings.appearance.errors.color_format')]),
            Select::make('grid_columns')
                ->label(__('shop.settings.appearance.fields.grid_columns'))
                ->options([3 => '3', 4 => '4'])
                ->required()
                ->native(false),
            Select::make('mobile_columns')
                ->label(__('shop.settings.appearance.fields.mobile_columns'))
                ->options([1 => '1', 2 => '2'])
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
                ->columnSpanFull(),
            // MB-24: «Приложение на телефоне».
            PwaSettingsSection::make()->columnSpanFull(),
        ]);
    }

    /**
     * MB-21: the icon set follows the saved icon and background color; then the replaced source file is removed.
     */
    protected function afterSave(): void
    {
        PwaSettingsSection::afterSave();
        $this->deleteReplacedSettingFiles();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['grid_columns'] = (int) $data['grid_columns'];
        $data['mobile_columns'] = (int) $data['mobile_columns'] === 1 ? 1 : 2;
        $data['per_page'] = (int) $data['per_page'];

        return PwaSettingsSection::mutate($data);
    }
}
