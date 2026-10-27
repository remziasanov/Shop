<?php

declare(strict_types=1);

use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use App\Settings\LegalSettings;
use App\Settings\SeoSettings;
use Spatie\LaravelSettings\Settings;

/**
 * @param  class-string<Settings>  $class
 * @param  array<string, mixed>  $values
 */
function setSettings(string $class, array $values): void
{
    $settings = app($class);

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();
}

/** @param array<string, mixed> $values */
function seoSettings(array $values): void
{
    setSettings(SeoSettings::class, $values);
}

/** @param array<string, mixed> $values */
function generalSettings(array $values): void
{
    setSettings(GeneralSettings::class, $values);
}

/** @param array<string, mixed> $values */
function appearanceSettings(array $values): void
{
    setSettings(AppearanceSettings::class, $values);
}

/** @param array<string, mixed> $values */
function legalSettings(array $values): void
{
    setSettings(LegalSettings::class, $values);
}
