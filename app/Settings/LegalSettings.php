<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LegalSettings extends Settings
{
    /** Seller details (name, INN/OGRN, address). */
    public ?string $seller_details;

    public string $consent_text;

    public bool $cookie_banner_enabled;

    public string $cookie_banner_text;

    /** Page id of the privacy policy (pages arrive in stage 5). */
    public ?int $privacy_page_id;

    /** Page id of the personal data consent. */
    public ?int $consent_page_id;

    public static function group(): string
    {
        return 'legal';
    }
}
