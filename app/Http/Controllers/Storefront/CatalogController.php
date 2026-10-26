<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsListing;
use App\Http\Requests\CatalogFilterRequest;
use App\Models\Category;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Contracts\View\View;

class CatalogController extends Controller
{
    use BuildsListing;

    /**
     * SF-10: root category tiles and all products.
     */
    public function index(CatalogFilterRequest $request, CategoryTree $tree, Breadcrumbs $breadcrumbs): View
    {
        $listing = $this->listing($request);

        return view('storefront.catalog.index', $listing + [
            'tiles' => $this->tiles($tree->roots()),
            'breadcrumbs' => $breadcrumbs->forCatalog(),
            'canonical' => $this->canonical(route('catalog.index'), $listing),
        ]);
    }

    /**
     * SF-11: category with its description, subcategory tiles and the products of it and all subcategories.
     */
    public function category(string $slug, CatalogFilterRequest $request, CategoryTree $tree, Breadcrumbs $breadcrumbs, HtmlSanitizer $sanitizer): View
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();
        abort_unless($tree->isVisible($category->id), 404);

        $node = $tree->node($category->id);
        $listing = $this->listing($request, $tree->descendantIds($category->id));

        return view('storefront.catalog.category', $listing + [
            'category' => $category,
            'description' => $sanitizer->sanitize($category->description),
            'tiles' => $this->tiles($node !== null ? $node->children : []),
            'breadcrumbs' => $breadcrumbs->forCategory($category->id),
            'canonical' => $this->canonical(route('catalog.category', $category->slug), $listing),
        ]);
    }

    /**
     * SF-14: the canonical URL drops only the sort parameter; filters and the page (when > 1) stay.
     *
     * @param  array<string, mixed>  $listing
     */
    private function canonical(string $url, array $listing): string
    {
        $query = $listing['filters']->toQuery(false);
        $page = $listing['paginator']->currentPage();

        if ($page > 1) {
            $query['page'] = $page;
        }

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
