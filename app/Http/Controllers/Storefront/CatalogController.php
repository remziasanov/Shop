<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Enums\StorefrontSection;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsListing;
use App\Http\Requests\CatalogFilterRequest;
use App\Models\Category;
use App\Services\Access\ViewerContext;
use App\Services\Age\AgeGate;
use App\Services\Age\AgeRestrictionResolver;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Seo\SeoBuilder;
use App\Services\Seo\SeoMeta;
use Illuminate\Contracts\View\View;

class CatalogController extends Controller
{
    use BuildsListing;

    /**
     * SF-10: root category tiles and all products.
     */
    public function index(CatalogFilterRequest $request, CategoryTree $tree, Breadcrumbs $breadcrumbs, SeoBuilder $seo, ViewerContext $viewer): View
    {
        $viewer->requireSection(StorefrontSection::Catalog);

        $listing = $this->listing($request);
        $trail = $breadcrumbs->forCatalog();
        $canonical = $this->canonical(route('catalog.index'), $listing);

        return view('storefront.catalog.index', $listing + [
            'tiles' => $this->tiles($tree->roots()),
            'breadcrumbs' => $trail,
            'canonical' => $canonical,
            'seo' => $seo->catalog($trail, $canonical),
        ]);
    }

    /**
     * SF-11: category with its description, subcategory tiles and the products of it and all subcategories.
     */
    public function category(string $slug, CatalogFilterRequest $request, CategoryTree $tree, Breadcrumbs $breadcrumbs, HtmlSanitizer $sanitizer, SeoBuilder $seo, AgeGate $ageGate, AgeRestrictionResolver $ageLevels, ViewerContext $viewer): View
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();
        abort_unless($tree->isVisible($category->id), 404);
        // CG-13: a category hidden from the group is a plain 404, as if it did not exist.
        $viewer->requireSection(StorefrontSection::Catalog);
        abort_unless($viewer->access()->canSeeCategory($category->id), 404);

        // AG-04: the overlay of the «restricted» mode asks for the effective level of this category.
        $ageGate->setPageLevel($ageLevels->forCategoryId($category->id));

        $node = $tree->node($category->id);
        $listing = $this->listing($request, $tree->descendantIds($category->id));
        $trail = $breadcrumbs->forCategory($category->id);
        $canonical = $this->canonical(route('catalog.category', $category->slug), $listing);

        return view('storefront.catalog.category', $listing + [
            'category' => $category,
            'description' => $sanitizer->sanitize($category->description),
            'tiles' => $this->tiles($node !== null ? $node->children : []),
            'breadcrumbs' => $trail,
            'canonical' => $canonical,
            'seo' => $this->noindexForPrivate($seo->category($category, $trail, $canonical), $viewer->noindexUnlessGuestSees(fn ($guest): bool => $guest->allowsSection(StorefrontSection::Catalog) && $guest->canSeeCategory($category->id))),
        ]);
    }

    private function noindexForPrivate(SeoMeta $meta, bool $private): SeoMeta
    {
        return $private ? $meta->withRobots('noindex,nofollow') : $meta;
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
