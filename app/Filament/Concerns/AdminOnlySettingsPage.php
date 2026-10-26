<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use UnitEnum;

/**
 * Shared behaviour of the shop settings pages: admin only, group «Настройки»,
 * labels from lang/ru/shop.php (`shop.settings.<key>.title`).
 *
 * The using page must define `protected static string $translationKey`.
 */
trait AdminOnlySettingsPage
{
    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('shop.settings.'.static::$translationKey.'.title');
    }

    public function getTitle(): string
    {
        return __('shop.settings.'.static::$translationKey.'.title');
    }

    public function getSavedNotificationTitle(): ?string
    {
        return __('shop.settings.saved');
    }
}
