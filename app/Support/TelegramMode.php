<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * The session flag of the Telegram Mini App mode (D-101, TM-06). The Telegram stage sets it when it opens a Mini App
 * session; until then it is never set. Orders and the PWA install hint read it through {@see self::active()}.
 */
final class TelegramMode
{
    public const SESSION_KEY = 'telegram_mode';

    public static function active(?Session $session = null): bool
    {
        $session ??= app()->bound('session.store') ? app('session.store') : null;

        return $session !== null && $session->get(self::SESSION_KEY) === true;
    }
}
