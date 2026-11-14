<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * TH-03, TH-04: the active theme and the per-theme overrides. A primary or accent color chosen before (SF-04) becomes
 * the override of the `default` theme, so the storefront looks the same after the update. The old fields stay as a
 * mirror (see AppearanceSettings), so `down()` only drops the new ones.
 */
return new class extends SettingsMigration
{
    private const LEGACY_PRIMARY = '#2563eb';

    private const LEGACY_ACCENT = '#f59e0b';

    public function up(): void
    {
        $primary = self::LEGACY_PRIMARY;
        $accent = self::LEGACY_ACCENT;

        $this->migrator->inGroup('appearance', function ($group) use (&$primary, &$accent): void {
            // Reading goes through update(): the modifier receives the stored value and returns it unchanged.
            $group->update('primary_color', function ($value) use (&$primary) {
                $primary = is_string($value) ? $value : $primary;

                return $value;
            });
            $group->update('accent_color', function ($value) use (&$accent) {
                $accent = is_string($value) ? $value : $accent;

                return $value;
            });
        });

        $colors = [];

        if (strtolower($primary) !== self::LEGACY_PRIMARY && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $primary) === 1) {
            $colors['primary'] = $primary;
        }

        if (strtolower($accent) !== self::LEGACY_ACCENT && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $accent) === 1) {
            $colors['accent'] = $accent;
        }

        $this->migrator->add('appearance.theme_id', 'default');
        $this->migrator->add('appearance.theme_overrides', $colors === [] ? [] : ['default' => ['colors' => $colors]]);
    }

    public function down(): void
    {
        $this->migrator->delete('appearance.theme_id');
        $this->migrator->delete('appearance.theme_overrides');
    }
};
