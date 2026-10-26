<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Seo\SitemapGenerator;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    /**
     * SF-82: serves public/sitemap.xml (normally the web server does that itself); generates it (under a lock) when missing, 503 + Retry-After while another process does.
     */
    public function __invoke(SitemapGenerator $generator): Response
    {
        $file = SitemapGenerator::directory().'/sitemap.xml';

        // Another process is already writing it (D-056): do not start a parallel generation.
        if (! is_file($file) && $generator->generateLocked() === null && ! is_file($file)) {
            return response('', 503, ['Retry-After' => '60', 'Cache-Control' => 'no-store']);
        }

        return response()->file($file, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
