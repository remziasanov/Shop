<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * The session flags of the Telegram Mini App mode (D-101, TM-03, TM-06). The mode comes from the session only, never
 * from a URL parameter: it is set by `POST /tg/session` after the signature of `initData` was verified. Orders, the cart
 * and the PWA install hint read it through {@see self::active()} and {@see self::accountId()}.
 */
final class TelegramMode
{
    public const SESSION_KEY = 'telegram_mode';

    /** `telegram_accounts.id` of the Mini App visitor (TM-03). */
    public const ACCOUNT_KEY = 'telegram_account_id';

    /** Telegram user id the session belongs to; another id resets the session. */
    public const USER_KEY = 'telegram_user_id';

    /** The customer signed in through the Telegram link (TM-04, «вход через Telegram»). */
    public const LOGIN_KEY = 'telegram_login';

    /** The signed `start_param` (or the unsigned `to` hint) to open after the session was created (TM-08). */
    public const START_KEY = 'telegram_start';

    public const HINT_KEY = 'telegram_start_hint';

    /** The bot may write to this user (chat started / write access allowed): no write-access request needed (TM-05). */
    public const CHAT_KEY = 'telegram_chat_started';

    /** A guest cart of the Telegram account waits to be merged into the signed-in customer's cart (TM-13). */
    public const CART_MERGE_KEY = 'telegram_cart_merge';

    public static function active(?Session $session = null): bool
    {
        $session ??= app()->bound('session.store') ? app('session.store') : null;

        return $session !== null && $session->get(self::SESSION_KEY) === true;
    }

    /**
     * The Telegram account of the Mini App session; null outside the Telegram mode.
     */
    public static function accountId(?Session $session = null): ?int
    {
        $session ??= app()->bound('session.store') ? app('session.store') : null;

        if ($session === null || $session->get(self::SESSION_KEY) !== true) {
            return null;
        }

        $id = $session->get(self::ACCOUNT_KEY);

        return is_int($id) && $id > 0 ? $id : null;
    }

    public static function telegramUserId(?Session $session = null): ?int
    {
        $session ??= app()->bound('session.store') ? app('session.store') : null;
        $id = $session?->get(self::USER_KEY);

        return is_int($id) && $id > 0 ? $id : null;
    }
}
