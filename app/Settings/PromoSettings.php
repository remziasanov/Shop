<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PromoSettings extends Settings
{
    /** PM-12: «Возвращать код при отмене» — a cancelled order gives its redemption back. */
    public bool $release_on_cancel;

    public static function group(): string
    {
        return 'promo';
    }
}
