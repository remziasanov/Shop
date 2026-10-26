<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class HomePageSettings extends Settings
{
    /**
     * Ordered home page blocks (Filament Builder state), edited in stage 5.
     */
    public array $blocks;

    public static function group(): string
    {
        return 'home_page';
    }
}
