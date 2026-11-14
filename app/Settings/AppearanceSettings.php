<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppearanceSettings extends Settings
{
    /** Hex color, e.g. #2563eb. */
    public string $primary_color;

    public string $accent_color;

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
