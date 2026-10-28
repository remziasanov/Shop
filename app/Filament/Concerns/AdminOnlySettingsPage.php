<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\Users\PermissionService;
use UnitEnum;

/**
 * Shared behaviour of the shop settings pages: group «Настройки», labels from lang/ru/shop.php
 * (`shop.settings.<key>.title`), rights from the permission catalog (RB-05, TZ 25.4).
 *
 * The using page must define `protected static string $translationKey`. Its permissions are
 * `settings.<page>.view` (open the page) and `settings.<page>.update` (save), where `<page>` is the translation key
 * unless the page overrides `permissionPage()`. A new settings page therefore
 * only needs its key to exist in the PermissionRegistry.
 */
trait AdminOnlySettingsPage
{
    /**
     * Opening the page needs the view or the update right of the page.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $permissions = app(PermissionService::class);

        return $permissions->allows($user, static::settingsPermission('view')) || $permissions->allows($user, static::settingsPermission('update'));
    }

    public static function settingsPermission(string $action): string
    {
        return 'settings.'.static::permissionPage().'.'.$action;
    }

    /**
     * The `<page>` part of the permission keys; override in a page whose key differs from its translation key.
     */
    public static function permissionPage(): string
    {
        return static::$translationKey;
    }

    /**
     * Saving needs the update right; a user with the view right only gets a read-only page.
     */
    public function canEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, static::settingsPermission('update'));
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
