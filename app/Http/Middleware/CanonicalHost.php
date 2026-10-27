<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TrustedHosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-057: absolute URLs are built from APP_URL, so the www./bare twin of the APP_URL host would load assets and
 * Livewire from another origin. The same holds for the hosts of SHOP_EXTRA_HOSTS (e.g. the Beget technical domain).
 * In production the twin and the extra hosts are redirected to the canonical host (301 for GET/HEAD, 308 otherwise
 * so the method and body are kept). Any other host is left to TrustHosts.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('shop.canonical_host_redirect', true) || ! app()->isProduction()) {
            return $next($request);
        }

        $canonical = TrustedHosts::canonical();
        $host = strtolower($request->getHost());

        if ($canonical === null || $host === $canonical || ! in_array($host, TrustedHosts::redirectedHosts($canonical), true)) {
            return $next($request);
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);
        $target = (is_string($scheme) ? $scheme : 'https').'://'.$canonical.($port ? ':'.$port : '').$request->getRequestUri();

        return new RedirectResponse($target, $request->isMethod('GET') || $request->isMethod('HEAD') ? 301 : 308);
    }
}
