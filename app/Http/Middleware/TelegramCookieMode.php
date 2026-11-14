<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\StorefrontCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TM-03, D-103: the session and XSRF-TOKEN cookies of the Mini App must work inside the Telegram Web frame, so they
 * are `SameSite=None; Secure; Partitioned`. Runs in the web group after StartSession and changes the cookie config
 * before the response is built (StartSession and the CSRF middleware read the config when they add their cookies).
 * Applies to `/tg*` unconditionally (even before the mode exists) and to every request in the Telegram mode; on a request
 * that is not HTTPS (development, tests) the config stays `lax` without Partitioned.
 */
class TelegramCookieMode
{
    /** @var array<string, mixed>|null the values before an override, restored by the next request of a long-lived process (tests, workers) */
    private static ?array $saved = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (StorefrontCookie::crossSite($request)) {
            self::$saved ??= [
                'session.same_site' => config('session.same_site'),
                'session.partitioned' => config('session.partitioned'),
                'session.secure' => config('session.secure'),
            ];

            config([
                'session.same_site' => 'none',
                'session.partitioned' => true,
                'session.secure' => true,
            ]);
        } elseif (self::$saved !== null) {
            config(self::$saved);
            self::$saved = null;
        }

        return $next($request);
    }
}
