<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Settings\AppearanceSettings;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * TH-29: «Телефон» mode of the theme preview: the storefront in a 390 x 844 frame. The frame loads the home page in the
 * staff session, so the preview theme chosen there is shown; without a started preview the active theme is shown.
 */
class ThemePhonePreview extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'theme-phone-preview';

    protected string $view = 'filament.pages.theme-phone-preview';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ThemePreview::class)->canPreview($user);
    }

    public function mount(): void
    {
        $session = request()->session();
        $user = Auth::user();

        // Opened without a preview (a bookmark): preview the active theme so the frame carries the bar.
        if (app(ThemePreview::class)->state($session, $user instanceof User ? $user : null) === null) {
            $settings = app(AppearanceSettings::class);
            $id = app(ThemeRegistry::class)->has($settings->theme_id) ? $settings->theme_id : ThemeRegistry::DEFAULT_ID;
            app(ThemePreview::class)->start($session, $id, (array) ($settings->theme_overrides[$id] ?? []));
        }
    }

    public function getTitle(): string
    {
        return __('themes.phone.title');
    }
}
