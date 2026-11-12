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
use App\Services\Catalog\AttributeFilterParser;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\OptionFilterParser;
use App\Services\Catalog\OptionSelection;
use App\Services\Content\HtmlSanitizer;
use App\Services\Seo\FilterSeoResolver;
use App\Services\Seo\SeoBuilder;
use App\Services\Seo\SeoMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

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
    public function category(string $slug, CatalogFilterRequest $request, CategoryTree $tree, Breadcrumbs $breadcrumbs, HtmlSanitizer $sanitizer, SeoBuilder $seo, AgeGate $ageGate, AgeRestrictionResolver $ageLevels, ViewerContext $viewer, FilterSeoResolver $filterSeo): View|RedirectResponse
    {
        $category = Category::query()->where('slug', $slug)->firstOrFail();
        abort_unless($tree->isVisible($category->id), 404);
        // CG-13: a category hidden from the group is a plain 404, as if it did not exist.
        $viewer->requireSection(StorefrontSection::Catalog);
        abort_unless($viewer->access()->canSeeCategory($category->id), 404);

        // AG-04: the overlay of the «restricted» mode asks for the effective level of this category.
        $ageGate->setPageLevel($ageLevels->forCategoryId($category->id));

        // FL-07: one URL per selection; a raw or differently ordered query string goes to the normalized one.
        $normalized = $this->normalizedUrl($request, $category);

        if ($normalized !== null) {
            return redirect()->to($normalized, 301);
        }

        $node = $tree->node($category->id);
        $listing = $this->listing($request, $tree->descendantIds($category->id), categoryId: $category->id);
        $trail = $breadcrumbs->forCategory($category->id);
        // FL-14: pages with characteristic filters are either the indexable «single value» page or noindex.
        $facetSeo = $filterSeo->resolve($category, $listing['filters'], $listing['paginator']->currentPage(), $listing['paginator']->total());
        $canonical = $facetSeo !== null ? $facetSeo->canonical : $this->canonical(route('catalog.category', $category->slug), $listing);
        $meta = $seo->category($category, $trail, $canonical, $facetSeo?->filtersText);

        return view('storefront.catalog.category', $listing + [
            'category' => $category,
            'heading' => $facetSeo !== null ? $facetSeo->heading : $category->name,
            'description' => $sanitizer->sanitize($category->description),
            'tiles' => $this->tiles($node !== null ? $node->children : []),
            'breadcrumbs' => $trail,
            'canonical' => $canonical,
            'seo' => $this->noindexForPrivate($facetSeo?->robots !== null ? $meta->withRobots($facetSeo->robots) : $meta, $viewer->noindexUnlessGuestSees(fn ($guest): bool => $guest->allowsSection(StorefrontSection::Catalog) && $guest->canSeeCategory($category->id))),
        ]);
    }

    /**
     * FL-07: the normalized URL of a category request with characteristic or option filters (`a[...]`, `o[...]`, VR-19) when its query string is
     * not the normalized one (raw `a[x][]=…`, empty min/max, another order, a value of a built-in parameter written
     * differently); null when it already is, when `a[...]` holds no valid filter, or for anything but GET. Other parameters (utm_*, …) are kept as they are.
     * The group's restrictions are not applied: a permanent redirect must not depend on who asks.
     */
    private function normalizedUrl(CatalogFilterRequest $request, Category $category): ?string
    {
        if (! $request->isMethod('GET') || ! ($request->query->has('a') || $request->query->has('o') || $request->query->has(OptionSelection::AVAILABLE_PARAM))) {
            return null;
        }

        $filters = $request->filters()
            ->withAttributes(app(AttributeFilterParser::class)->parse($request->query(), $category->id))
            ->withOptions(app(OptionFilterParser::class)->parse($request->query(), $category->id));

        // Nothing valid in `a[...]` / `o[...]` (unknown slugs, no set yet): the page shows the whole category, nothing to normalize.
        if ($filters->attributes->isEmpty() && $filters->options->isEmpty()) {
            return null;
        }

        $normalized = $filters->toQuery(true);

        if ($filters->page > 1) {
            $normalized['page'] = $filters->page;
        }

        $known = ['sort', 'price_from', 'price_to', 'in_stock', 'brands', 'flags', 'page', 'a', 'o', OptionSelection::AVAILABLE_PARAM];
        $raw = $request->query();
        $rawKnown = array_intersect_key($raw, array_flip($known));

        if (http_build_query($rawKnown) === http_build_query($normalized)) {
            return null;
        }

        $query = $normalized + array_diff_key($raw, array_flip($known));
        $url = route('catalog.category', $category->slug);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
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
