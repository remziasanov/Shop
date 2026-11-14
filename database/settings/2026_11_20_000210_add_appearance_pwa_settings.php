<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * MB-24: «Приложение на телефоне» (PWA) fields of the appearance settings. Off by default; it cannot be switched on
 * without an app icon (MB-21).
 */
return new class extends SettingsMigration
{
    private const FIELDS = [
        'pwa_enabled' => false,
        'pwa_name' => '',
        'pwa_short_name' => '',
        'pwa_icon' => null,
        'pwa_theme_color' => '#2563eb',
        'pwa_background_color' => '#ffffff',
        'pwa_install_hint' => true,
        'pwa_icons_hash' => null,
    ];

    public function up(): void
    {
        foreach (self::FIELDS as $name => $default) {
            $this->migrator->add('appearance.'.$name, $default);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FIELDS) as $name) {
            $this->migrator->delete('appearance.'.$name);
        }
    }
};
