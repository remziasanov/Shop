<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $shop_name;

    public ?string $tagline;

    /** Public disk path, e.g. settings/logo.png. */
    public ?string $logo;

    /** Public disk path. */
    public ?string $favicon;

    /** @var array<int, string> */
    public array $phones;

    public ?string $email;

    public ?string $address;

    public ?string $working_hours;

    /**
     * Items: title, url.
     */
    public array $social_links;

    public ?string $footer_text;

    public bool $shop_open;

    public ?string $closed_message;

    public static function group(): string
    {
        return 'general';
    }
}
