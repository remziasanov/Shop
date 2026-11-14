<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppearanceSettings extends Settings
{
    /**
     * Legacy SF-04 colors. Since TH-03 the live values are the `default` theme overrides in {@see $theme_overrides};
     * these two mirror the resolved primary and accent of the active theme for code outside the theme system
     * (error pages), see ThemeManager.
     */
    public string $primary_color;

    public string $accent_color;

    /** Id of the active theme (TH-01…TH-04); `default` is the previous look. */
    public string $theme_id;

    /**
     * Overrides per theme id (TH-04, D-136): `{<theme id>: {colors: {bg, surface, ink, primary, accent}, fonts: {display, body}}}`,
     * every key optional; sanitized on read by ThemeResolver.
     */
    public array $theme_overrides;

    /** Product grid columns on desktop: 3 or 4. */
    public int $grid_columns;

    public int $per_page;

    /** Product grid columns on a phone (MB-07): 1 or 2. */
    public int $mobile_columns;

    /** Public disk path of the product placeholder image. */
    public ?string $placeholder_image;

    /** MB-24: the shop can be installed on a phone (manifest, theme color, install hint). Needs {@see $pwa_icon}. */
    public bool $pwa_enabled;

    /** Full app name; empty = the shop name. */
    public string $pwa_name;

    /** Name under the home screen icon, up to 12 characters; empty = cut from the full name. */
    public string $pwa_short_name;

    /** Public disk path of the uploaded square app icon (MB-21). */
    public ?string $pwa_icon;

    public string $pwa_theme_color;

    public string $pwa_background_color;

    /** MB-22: show «Установить приложение» and the iPhone instruction. */
    public bool $pwa_install_hint;

    /** Content hash of the generated icon set in `app-icons/` (icon and background color), see AppIconGenerator. */
    public ?string $pwa_icons_hash;

    public static function group(): string
    {
        return 'appearance';
    }
}
