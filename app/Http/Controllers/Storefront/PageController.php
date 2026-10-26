<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class PageController extends Controller
{
    /**
     * SF-70: a static page. Inactive pages are 404 (the slug-change redirect handler may still turn that into a 301, SF-80).
     */
    public function show(string $slug, Breadcrumbs $breadcrumbs, HtmlSanitizer $sanitizer): View
    {
        $page = Page::query()->active()->where('slug', $slug)->firstOrFail();
        $content = $sanitizer->sanitize($page->content);

        return view('storefront.page.show', [
            'page' => $page,
            'content' => $content,
            'breadcrumbs' => $breadcrumbs->forPage($page->title),
            'pageTitle' => $page->meta_title ?: $page->title,
            'metaDescription' => $page->meta_description ?: Str::limit(trim(strip_tags($content)), 160),
        ]);
    }
}
