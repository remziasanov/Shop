<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings\Sections;

use App\Exceptions\ImageProcessingException;
use App\Services\Media\AppIconGenerator;
use App\Services\Pwa\PwaService;
use App\Settings\AppearanceSettings;
use App\Support\ShopName;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * «Оформление → Приложение на телефоне» (MB-24): the installable app switch, names, icon, colors, install hint and a
 * phone-screen preview. Kept out of the page class; the page calls {@see make()}, {@see mutate()} and {@see afterSave()}.
 */
final class PwaSettingsSection
{
    private const HEX_COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    public static function make(): Section
    {
        return Section::make(__('pwa.admin.section'))
            ->description(__('pwa.admin.section_description'))
            ->schema([
                Toggle::make('pwa_enabled')
                    ->label(__('pwa.admin.enabled'))
                    // MB-21: no icon, no app. A disabled field is not sent, so keep it in the state (mutate() resets it).
                    ->disabled(fn (Get $get): bool => ! self::hasIcon($get('pwa_icon')))
                    ->dehydrated()
                    ->helperText(fn (Get $get): string => self::hasIcon($get('pwa_icon')) ? __('pwa.admin.enabled_help') : __('pwa.admin.enable_needs_icon')),
                TextInput::make('pwa_name')
                    ->label(__('pwa.admin.name'))
                    ->helperText(__('pwa.admin.name_help'))
                    ->maxLength(100)
                    ->live(onBlur: true),
                TextInput::make('pwa_short_name')
                    ->label(__('pwa.admin.short_name'))
                    ->helperText(__('pwa.admin.short_name_help'))
                    ->maxLength(PwaService::SHORT_NAME_MAX)
                    ->live(onBlur: true),
                FileUpload::make('pwa_icon')
                    ->label(__('pwa.admin.icon'))
                    ->helperText(__('pwa.admin.icon_help'))
                    ->disk('public')
                    ->directory('settings')
                    ->visibility('public')
                    // NF-11: no SVG; the type is also checked by the file header in the rule below.
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(10240)
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        $contents = self::contents($value);

                        if ($contents === null) {
                            return;
                        }

                        try {
                            app(AppIconGenerator::class)->validate($contents);
                        } catch (ImageProcessingException $e) {
                            $fail($e->getMessage());
                        }
                    })
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        if (! self::hasIcon($get('pwa_icon'))) {
                            $set('pwa_enabled', false);
                        }
                    })
                    ->columnSpanFull(),
                ColorPicker::make('pwa_theme_color')
                    ->label(__('pwa.admin.theme_color'))
                    ->required()
                    ->regex(self::HEX_COLOR_PATTERN)
                    ->validationMessages(['regex' => __('shop.settings.appearance.errors.color_format')]),
                ColorPicker::make('pwa_background_color')
                    ->label(__('pwa.admin.background_color'))
                    ->helperText(__('pwa.admin.background_help'))
                    ->required()
                    ->regex(self::HEX_COLOR_PATTERN)
                    ->validationMessages(['regex' => __('shop.settings.appearance.errors.color_format')])
                    ->live(onBlur: true),
                Toggle::make('pwa_install_hint')
                    ->label(__('pwa.admin.install_hint'))
                    ->helperText(__('pwa.admin.install_hint_help')),
                View::make('filament.settings.pwa-preview')
                    ->viewData(fn (Get $get): array => ['preview' => self::preview($get)])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The app cannot be on without an icon (MB-21); the short name never exceeds the manifest limit (MB-20).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutate(array $data): array
    {
        $icon = $data['pwa_icon'] ?? null;
        $hasIcon = is_string($icon) && $icon !== '';

        $data['pwa_icon'] = $hasIcon ? $icon : null;
        $data['pwa_enabled'] = $hasIcon && (bool) ($data['pwa_enabled'] ?? false);
        $data['pwa_install_hint'] = (bool) ($data['pwa_install_hint'] ?? false);
        $data['pwa_name'] = trim((string) ($data['pwa_name'] ?? ''));
        $data['pwa_short_name'] = mb_substr(trim((string) ($data['pwa_short_name'] ?? '')), 0, PwaService::SHORT_NAME_MAX);

        return $data;
    }

    /**
     * Generates the icon set for the saved settings (MB-21). A failure switches the app off instead of failing the save.
     */
    public static function afterSave(): void
    {
        $settings = app(AppearanceSettings::class);

        try {
            app(AppIconGenerator::class)->sync($settings);
        } catch (Throwable $e) {
            report($e);

            $settings->pwa_enabled = false;
            $settings->pwa_icons_hash = null;
            $settings->save();

            Notification::make()
                ->danger()
                ->title(__('pwa.admin.saved_icons_failed'))
                ->body($e instanceof ImageProcessingException ? $e->getMessage() : null)
                ->send();
        }
    }

    private static function hasIcon(mixed $state): bool
    {
        return is_array($state) ? array_filter($state) !== [] : (is_string($state) && $state !== '');
    }

    /**
     * Bytes of an icon from the form state: a fresh upload (temporary file) or a stored path.
     */
    private static function contents(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value instanceof TemporaryUploadedFile) {
            $contents = @file_get_contents($value->getRealPath());

            return $contents === false ? null : $contents;
        }

        if (is_string($value) && $value !== '' && Storage::disk('public')->exists($value)) {
            return (string) Storage::disk('public')->get($value);
        }

        return null;
    }

    /**
     * @return array{icon: ?string, name: string, background: string}
     */
    private static function preview(Get $get): array
    {
        $state = $get('pwa_icon');
        $file = is_array($state) ? reset($state) : $state;
        $icon = null;

        try {
            if ($file instanceof TemporaryUploadedFile) {
                $icon = $file->temporaryUrl();
            } elseif (is_string($file) && $file !== '') {
                $icon = Storage::disk('public')->url($file);
            }
        } catch (Throwable) {
            $icon = null;
        }

        $name = trim((string) $get('pwa_short_name'));

        if ($name === '') {
            $name = trim((string) $get('pwa_name'));
        }

        if ($name === '') {
            $name = ShopName::get();
        }

        $background = (string) $get('pwa_background_color');

        return [
            'icon' => $icon,
            'name' => mb_substr($name, 0, PwaService::SHORT_NAME_MAX),
            'background' => preg_match(self::HEX_COLOR_PATTERN, $background) === 1 ? $background : PwaService::DEFAULT_BACKGROUND,
        ];
    }
}
