<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Content\HtmlSanitizer;
use App\Services\Seo\SeoBuilder;
use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    /**
     * SF-70: a static page. Inactive pages are 404 (the slug-change redirect handler may still turn that into a 301, SF-80).
     */
    public function show(string $slug, Breadcrumbs $breadcrumbs, HtmlSanitizer $sanitizer, SeoBuilder $seo): View
    {
        $page = Page::query()->active()->where('slug', $slug)->firstOrFail();
        $content = $sanitizer->sanitize($page->content);

        $trail = $breadcrumbs->forPage($page->title);

        return view('storefront.page.show', [
            'page' => $page,
            'content' => $content,
            'breadcrumbs' => $trail,
            'seo' => $seo->page($page->title, $page->meta_title, $page->meta_description, $content, route('page.show', $page->slug), $trail),
        ]);
    }
}
