<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\GeneralSettings;
use Throwable;

/**
 * The shop name shown to people: «Настройки → Основные → Название магазина». APP_NAME is only the technical
 * fallback (fresh install before the settings migration, CLI) — it also names cookies and cache keys, so it never
 * follows the admin setting.
 */
final class ShopName
{
    public static function get(): string
    {
        try {
            $name = trim(app(GeneralSettings::class)->shop_name);
        } catch (Throwable) {
            $name = '';
        }

        return $name !== '' ? $name : (string) config('app.name');
    }
}
