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

    /** Public disk path of the product placeholder image. */
    public ?string $placeholder_image;

    public static function group(): string
    {
        return 'appearance';
    }
}
