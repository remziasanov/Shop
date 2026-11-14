<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Filament\Concerns\DeletesReplacedSettingFiles;
use App\Filament\Pages\Settings\Sections\PwaSettingsSection;
use App\Models\User;
use App\Services\Themes\FontRegistry;
use App\Services\Themes\Theme;
use App\Services\Themes\ThemeManager;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ManageAppearanceSettings extends SettingsPage
{
    use AdminOnlySettingsPage;
    use DeletesReplacedSettingFiles {
        afterSave as protected deleteReplacedSettingFiles;
    }

    private const HEX_COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /** Overrides of the form for the pending save (set in mutateFormDataBeforeSave, used in afterSave). @var array<string, mixed> */
    private array $pendingOverrides = [];

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
            Hidden::make('theme_id'),
            // TH-22: gallery of themes, grouped by shop type; «Выбрать» and «Предпросмотр» call page methods.
            Section::make(__('themes.admin.section_theme'))
                ->description(__('themes.admin.section_theme_hint'))
                ->schema([
                    View::make('filament.settings.theme-gallery')->viewData(fn (): array => ['groups' => $this->galleryGroups(), 'canEdit' => $this->canEdit()]),
                ])
                ->columnSpanFull(),
            // TH-23, TH-24: five colors and two fonts, empty = the theme value.
            Section::make(__('themes.admin.section_overrides'))
                ->description(__('themes.admin.section_overrides_hint'))
                ->schema([
                    ...array_map(
                        fn (string $key): ColorPicker => ColorPicker::make('ov_colors.'.$key)
                            ->label(__('themes.admin.colors.'.$key))
                            ->regex(self::HEX_COLOR_PATTERN)
                            ->validationMessages(['regex' => __('shop.settings.appearance.errors.color_format')])
                            ->helperText(fn (): string => __('themes.admin.theme_value', ['value' => $this->themeColor($key)])),
                        ThemeResolver::OVERRIDABLE_COLORS,
                    ),
                    Select::make('ov_fonts.display')
                        ->label(__('themes.admin.font_display'))
                        ->options(fn (): array => array_combine(array_keys(app(FontRegistry::class)->all()), array_keys(app(FontRegistry::class)->all())))
                        ->placeholder(__('themes.admin.font_as_theme'))
                        ->searchable()
                        ->native(false),
                    Select::make('ov_fonts.body')
                        ->label(__('themes.admin.font_body'))
                        ->options(fn (): array => array_combine(array_keys(app(FontRegistry::class)->bodyChoices()), array_keys(app(FontRegistry::class)->bodyChoices())))
                        ->placeholder(__('themes.admin.font_as_theme'))
                        ->searchable()
                        ->native(false),
                    View::make('filament.settings.theme-reset-buttons')->viewData(fn (): array => ['canEdit' => $this->canEdit()])->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $overrides = app(ThemeManager::class)->overridesFor((string) $data['theme_id']);
        $data['ov_colors'] = $overrides['colors'] ?? [];
        $data['ov_fonts'] = $overrides['fonts'] ?? [];

        return $data;
    }

    /**
     * TH-04: switching the theme is one action; it saves at once and loads the overrides stored for that theme.
     */
    public function selectTheme(string $id): void
    {
        $user = $this->staff();
        abort_unless($user !== null && $this->canEdit(), 403);

        $registry = app(ThemeRegistry::class);

        if (! $registry->has($id)) {
            return;
        }

        app(ThemeManager::class)->select($user, $id);
        $this->data['theme_id'] = $id;
        $this->loadOverridesOf($id);

        Notification::make()->success()->title(__('themes.admin.selected_notification', ['name' => $registry->findOrDefault($id)->name]))->send();
    }

    public function resetColors(): void
    {
        $user = $this->staff();
        abort_unless($user !== null && $this->canEdit(), 403);

        app(ThemeManager::class)->resetColors($user, $this->currentThemeId());
        $this->data['ov_colors'] = [];

        Notification::make()->success()->title(__('themes.admin.colors_reset'))->send();
    }

    public function resetFonts(): void
    {
        $user = $this->staff();
        abort_unless($user !== null && $this->canEdit(), 403);

        app(ThemeManager::class)->resetFonts($user, $this->currentThemeId());
        $this->data['ov_fonts'] = [];

        Notification::make()->success()->title(__('themes.admin.fonts_reset'))->send();
    }

    /**
     * TH-27: starts the staff preview of a theme in a new tab. For the theme of the form the unsaved colors and fonts
     * are taken; another theme is shown with its stored overrides.
     */
    public function previewTheme(string $id): void
    {
        $user = $this->staff();
        $previews = app(ThemePreview::class);
        abort_unless($previews->canPreview($user) && $this->canEdit(), 403);

        if (! app(ThemeRegistry::class)->has($id)) {
            return;
        }

        $overrides = $id === $this->currentThemeId()
            ? $this->overridesFromState()
            : app(ThemeManager::class)->overridesFor($id);

        $previews->start(app('session.store'), $id, $overrides);
        $this->js('window.open('.json_encode(route('home'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT).', "_blank")');
    }

    /**
     * Gallery data: groups by shop type, each with theme cards (TH-22). `default` has its own group.
     *
     * @return list<array{title: string, themes: list<array<string, mixed>>}>
     */
    public function galleryGroups(): array
    {
        $registry = app(ThemeRegistry::class);
        $current = $this->currentThemeId();
        $groups = [['title' => __('themes.default_group'), 'themes' => [$this->card($registry->default(), $current)]]];

        foreach ($registry->groups() as $group) {
            $themes = array_map(fn (Theme $theme): array => $this->card($theme, $current), $registry->grouped()[$group['id']] ?? []);

            if ($themes !== []) {
                $groups[] = ['title' => $group['name'], 'themes' => $themes];
            }
        }

        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Theme $theme, string $current): array
    {
        $font = function (string $role) use ($theme): string {
            $family = app(FontRegistry::class)->find($theme->fonts[$role]['family'] ?? null);

            return $family !== null ? $family->family : __('themes.admin.system_font');
        };

        return [
            'id' => $theme->id,
            'name' => $theme->name,
            'niche' => $theme->niche,
            'active' => $theme->id === $current,
            'swatches' => array_map(fn (string $key): string => (string) ($theme->colors[$key] ?? ''), ['bg', 'surface', 'ink', 'primary', 'accent']),
            'display' => $font('display'),
            'body' => $font('body'),
        ];
    }

    private function themeColor(string $key): string
    {
        $theme = app(ThemeRegistry::class)->findOrDefault($this->currentThemeId());

        return (string) ($theme->colors[$key] ?? '');
    }

    private function currentThemeId(): string
    {
        $id = (string) ($this->data['theme_id'] ?? app(AppearanceSettings::class)->theme_id);

        return app(ThemeRegistry::class)->has($id) ? $id : ThemeRegistry::DEFAULT_ID;
    }

    private function loadOverridesOf(string $id): void
    {
        $overrides = app(ThemeManager::class)->overridesFor($id);
        $this->data['ov_colors'] = $overrides['colors'] ?? [];
        $this->data['ov_fonts'] = $overrides['fonts'] ?? [];
    }

    /**
     * The overrides currently typed in the form (not validated by the form, sanitized by the resolver).
     *
     * @return array<string, mixed>
     */
    private function overridesFromState(): array
    {
        return app(ThemeResolver::class)->sanitizeOverrides([
            'colors' => (array) ($this->data['ov_colors'] ?? []),
            'fonts' => (array) ($this->data['ov_fonts'] ?? []),
        ]);
    }

    private function staff(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * MB-21: the icon set follows the saved icon and background color; then the replaced source file is removed.
     */
    protected function afterSave(): void
    {
        $this->saveThemeOverrides();
        PwaSettingsSection::afterSave();
        $this->deleteReplacedSettingFiles();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['grid_columns'] = (int) $data['grid_columns'];
        $data['mobile_columns'] = (int) $data['mobile_columns'] === 1 ? 1 : 2;
        $data['per_page'] = (int) $data['per_page'];

        // Theme data is saved by ThemeManager (audit, per-theme storage); the plain settings fill must not touch it.
        $this->pendingOverrides = $this->overridesFromState();
        unset($data['theme_id'], $data['ov_colors'], $data['ov_fonts']);

        return PwaSettingsSection::mutate($data);
    }

    /**
     * TH-04, TH-08, TH-26: stores the overrides of the form for the current theme and warns about low contrast.
     */
    private function saveThemeOverrides(): void
    {
        $user = $this->staff();

        if ($user === null) {
            return;
        }

        $warnings = app(ThemeManager::class)->saveOverrides($user, $this->currentThemeId(), $this->pendingOverrides);

        if ($warnings !== []) {
            $pairs = implode(', ', array_map(fn (array $w): string => __('themes.admin.contrast_pairs.'.$w['pair']).' ('.number_format($w['ratio'], 2, ',', '').':1)', $warnings));

            Notification::make()->warning()->title(__('themes.admin.contrast_title'))->body(__('themes.admin.contrast_body', ['pairs' => $pairs]))->persistent()->send();
        }
    }
}
