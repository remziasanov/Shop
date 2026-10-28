<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\AgeGateMode;
use Spatie\LaravelSettings\Settings;

/**
 * Age gate (TZ 16, AG-02). Texts may contain the {age} placeholder.
 */
class AgeGateSettings extends Settings
{
    public bool $enabled;

    public AgeGateMode $mode;

    /** Age asked by the «site» mode: 16 or 18. */
    public int $site_min_age;

    /** How long the answer is remembered (1–365 days). */
    public int $remember_days;

    public string $title;

    public string $text;

    public string $yes_label;

    public string $no_label;

    public string $decline_message;

    /** Show the «16+» / «18+» badge on product cards. */
    public bool $show_badge;

    public static function group(): string
    {
        return 'age_gate';
    }
}
