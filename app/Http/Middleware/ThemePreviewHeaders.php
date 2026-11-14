<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Themes\ActiveTheme;
use App\Services\Themes\ThemePreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TH-30: a response rendered in a staff theme preview is private, never stored and not indexable.
 */
class ThemePreviewHeaders
{
    public function __construct(private readonly ActiveTheme $theme) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && $request->session()->has(ThemePreview::SESSION_KEY) && $this->theme->isPreview()) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
