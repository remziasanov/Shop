<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Seo\SitemapGenerator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SitemapController extends Controller
{
    /**
     * SF-82: serves public/sitemap.xml (normally the web server does that itself); generates it when missing.
     */
    public function __invoke(SitemapGenerator $generator): BinaryFileResponse
    {
        $file = public_path('sitemap.xml');

        if (! is_file($file)) {
            $generator->generate();
        }

        return response()->file($file, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
