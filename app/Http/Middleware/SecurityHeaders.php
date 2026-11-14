<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TelegramMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline security headers to every response (NF-13).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->framableByTelegram($request)) {
            // TM-12, D-103: the Mini App runs in a frame of Telegram Web; nobody else may frame the shop.
            $response->headers->remove('X-Frame-Options');
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' https://web.telegram.org");
        } else {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }

    /**
     * `/tg*` and every storefront response of a Telegram Mini App session; the admin panel is never framable.
     */
    private function framableByTelegram(Request $request): bool
    {
        $admin = trim((string) config('shop.admin_path'), '/');

        if ($admin !== '' && $request->is($admin, $admin.'/*')) {
            return false;
        }

        return $request->is('tg', 'tg/*') || TelegramMode::active();
    }
}
