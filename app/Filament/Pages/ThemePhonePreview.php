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
        $session = app('session.store');
        $user = Auth::user();

        $previews = app(ThemePreview::class);
        $state = $previews->state($session, $user instanceof User ? $user : null);

        // A preview left by the gallery overlay carries `embed` (no bar inside a frame): restart it without the flag,
        // so the frame of this page always shows the storefront bar.
        if ($state !== null && $state['embed']) {
            $previews->start($session, $state['theme_id'], $state['overrides']);
        }

        // Opened without a preview (a bookmark): preview the active theme so the frame carries the bar.
        if ($state === null) {
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
