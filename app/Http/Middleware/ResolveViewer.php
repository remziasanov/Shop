<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Access\ViewerContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Storefront viewer (CG-16): starts every request with a clean {@see ViewerContext} and, after the response is built,
 * marks pages that only a non-guest viewer may see (or any page of a staff preview) as not indexable and not
 * cacheable by shared caches (CG-15).
 */
class ResolveViewer
{
    public function __construct(private readonly ViewerContext $viewer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->viewer->beginRequest();

        $response = $next($request);

        if ($this->viewer->isPrivate() || $this->viewer->isPreview()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'private, no-store');
        } elseif ($this->viewer->customer() !== null) {
            // 26.3: pages shown to a signed-in customer (name in the header, group prices) are never shared-cached.
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
