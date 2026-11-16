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

        // D-202: one CSP header with every directive. No script-src: the storefront has inline scripts and the
        // admin-editable head/body code (a script policy would need nonces).
        $csp = ["object-src 'none'", "base-uri 'self'"];
        $frameAncestors = null;

        if ($this->framableByTelegram($request)) {
            // TM-12, D-103: the Mini App runs in a frame of Telegram Web; nobody else may frame the shop.
            $response->headers->remove('X-Frame-Options');
            $frameAncestors = "frame-ancestors 'self' https://web.telegram.org";
            array_unshift($csp, $frameAncestors);
        } else {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // D-202: a PDF (the admin order PDF is shown inline) gets no `object-src 'none'`, which can stop the built-in PDF
        // viewer of Chromium browsers, and no markup policy; only `frame-ancestors` stays in the Telegram mode, where
        // X-Frame-Options is removed (outside it X-Frame-Options SAMEORIGIN above already protects the PDF).
        if (str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'application/pdf')) {
            if ($frameAncestors !== null) {
                $response->headers->set('Content-Security-Policy', $frameAncestors);
            }
        } else {
            $response->headers->set('Content-Security-Policy', implode('; ', $csp));
        }

        if ($request->isSecure() && app()->isProduction()) {
            // No includeSubDomains / preload: the shop does not own the other subdomains of its domain.
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000');
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
