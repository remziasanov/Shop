<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\TelegramCookieMode;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The one factory of the storefront cookies (TM-03, D-103): cart, age gate (AG-06), promo attribution (PM) and the cookie
 * consent (SF-08). The session and XSRF-TOKEN cookies get the same attributes through the config the middleware
 * {@see TelegramCookieMode} sets. In the Telegram mode (and on `/tg*`) over HTTPS the cookies are
 * `SameSite=None; Secure; Partitioned`, so they work inside the Telegram Web frame; everywhere else they stay `Lax`
 * (SameSite=None needs Secure, so plain HTTP, local development and tests keep Lax without Partitioned).
 */
final class StorefrontCookie
{
    /**
     * Whether cookies of this request must be usable inside a frame of another site: the Telegram mode or a `/tg*`
     * page, and a secure connection.
     */
    public static function crossSite(?Request $request = null): bool
    {
        $request ??= request();

        if (! $request->isSecure()) {
            return false;
        }

        return $request->is('tg', 'tg/*') || TelegramMode::active();
    }

    /**
     * @param  bool|null  $secure  null: the session setting, then the connection
     */
    public static function make(string $name, string $value, int $minutes, bool $httpOnly = true, ?bool $secure = null, ?Request $request = null): Cookie
    {
        $request ??= request();

        return self::build($name, $value, now()->addMinutes($minutes)->getTimestamp(), $httpOnly, $secure, $request);
    }

    /**
     * Same as {@see make()} with an absolute expiry (a Unix time) or 0 for a session cookie.
     */
    public static function build(string $name, string $value, int $expire, bool $httpOnly = true, ?bool $secure = null, ?Request $request = null): Cookie
    {
        $request ??= request();

        if (self::crossSite($request)) {
            return new Cookie($name, $value, $expire, '/', null, true, $httpOnly, false, Cookie::SAMESITE_NONE, true);
        }

        return new Cookie($name, $value, $expire, '/', null, $secure ?? (bool) (config('session.secure') ?? $request->isSecure()), $httpOnly, false, Cookie::SAMESITE_LAX);
    }

    /**
     * Attributes for a cookie written by JavaScript (the consent banner), as `; `-joined text without the leading
     * separator. The page itself decides on `Secure` by `location.protocol` when the server did not.
     */
    public static function scriptAttributes(?Request $request = null): string
    {
        return self::crossSite($request) ? 'SameSite=None; Secure; Partitioned' : 'SameSite=Lax';
    }
}
