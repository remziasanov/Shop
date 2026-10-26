<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SeoSettings extends Settings
{
    public string $product_title_template;

    public string $product_description_template;

    public string $category_title_template;

    public string $category_description_template;

    public ?string $home_title;

    public ?string $home_description;

    public string $robots_txt;

    /** Raw HTML inserted into <head> (counters). Admin only. */
    public ?string $head_code;

    /** Raw HTML inserted before </body>. Admin only. */
    public ?string $body_code;

    /** Verification <meta> tags, one per line. */
    public ?string $verification_meta;

    /** Public disk path of the default Open Graph image. */
    public ?string $og_image;

    public static function group(): string
    {
        return 'seo';
    }
}
