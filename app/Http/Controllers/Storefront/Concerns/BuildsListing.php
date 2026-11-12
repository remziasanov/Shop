<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Concerns;

use App\Enums\CatalogSort;
use App\Enums\StorefrontSection;
use App\Http\Requests\CatalogFilterRequest;
use App\Services\Access\AccessSet;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\AttributeFilterParser;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\FacetService;
use App\Services\Catalog\FilterChips;
use App\Services\Catalog\OptionFilterParser;
use App\Services\Catalog\ProductPresenter;
use App\Services\Catalog\StorefrontFilterSets;
use App\Services\Search\VariantSearch;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Data shared by every product listing page (catalog, category, search): products, brands, filters, sort.
 */
trait BuildsListing
{
    /**
     * Characteristic and option filters (FL-07, VR-19) exist only on category pages (FL-15): pass `$categoryId` there, the filters of
     * the category's set are parsed from the query string (more than 10 of them answer 404) and the facets built.
     *
     * @param  list<int>|null  $categoryIds
     * @param  array<string, mixed>  $extraQuery
     * @return array<string, mixed>
     */
    private function listing(
        CatalogFilterRequest $request,
        ?array $categoryIds = null,
        ?string $search = null,
        array $extraQuery = [],
        ?int $categoryId = null,
    ): array {
        $access = app(ViewerContext::class)->access();
        $filters = $request->filters();

        $set = $categoryId !== null ? app(StorefrontFilterSets::class)->for($categoryId) : null;

        if ($categoryId !== null) {
            $filters = $filters->withAttributes(app(AttributeFilterParser::class)->parse($request->query(), $categoryId, $set))
                ->withOptions(app(OptionFilterParser::class)->parse($request->query(), $categoryId, $set));
        }

        $filters = $filters->restrictedTo($access);
        $result = app(CatalogQuery::class)->paginate($filters, $categoryIds, $search, $extraQuery);
        $facets = $categoryId !== null && $categoryIds !== null ? app(FacetService::class)->forCategory($categoryId, $categoryIds, $filters, $set) : [];
        $chips = app(FilterChips::class);

        return [
            // VR-21: a card found by the article or barcode of one variant opens on that variant.
            'cards' => app(ProductPresenter::class)->many($result->products, true, $search !== null ? app(VariantSearch::class)->linksFor($search, $result->products->getCollection()) : []),
            'paginator' => $result->products,
            'brands' => $result->brands,
            'filters' => $filters,
            'sortOptions' => $this->sortOptions($access->showsPrices()),
            'showPrices' => $access->showsPrices(),
            'flagOptions' => $this->flagOptions($access),
            'gridColumns' => app(AppearanceSettings::class)->grid_columns === 3 ? 3 : 4,
            'formAction' => $request->url(),
            'extraQuery' => $extraQuery,
            'facets' => $facets,
            'optionToggle' => $set !== null && $set->hasOptions(),
            'chips' => $chips->build($filters, $request->url(), $extraQuery),
            'resetUrl' => $chips->resetUrl($request->url(), $extraQuery),
        ];
    }

    /**
     * Label filters the group may use (CG-04): «хит» / «новинка» need the featured section, «скидка» the promotions
     * section and visible prices.
     *
     * @return list<string>
     */
    private function flagOptions(AccessSet $access): array
    {
        $flags = [];

        if ($access->allowsSection(StorefrontSection::Featured)) {
            $flags = ['hit', 'new'];
        }

        if ($access->allowsSection(StorefrontSection::Promotions) && $access->showsPrices()) {
            $flags[] = 'sale';
        }

        return $flags;
    }

    /**
     * Sort options; the price sorts disappear when the group sees no prices (CG-06).
     *
     * @return array<string, string>
     */
    private function sortOptions(bool $showPrices): array
    {
        $options = CatalogSort::options();

        if (! $showPrices) {
            unset($options[CatalogSort::PriceAsc->value], $options[CatalogSort::PriceDesc->value]);
        }

        return $options;
    }

    /**
     * Category tiles (SF-10, SF-11) from the cached tree: active categories only.
     *
     * @param  list<CategoryNode>  $nodes
     * @return list<array{name: string, url: string, image: string|null, count: int}>
     */
    private function tiles(array $nodes): array
    {
        $access = app(ViewerContext::class)->access();
        $counts = app(CategoryTree::class)->productCountsWithDescendants(true, $access);
        $tiles = [];

        foreach ($nodes as $node) {
            if (! $node->isActive || ! $access->canSeeCategory($node->id)) {
                continue;
            }

            $tiles[] = [
                'name' => $node->name,
                'url' => route('catalog.category', $node->slug),
                'image' => $node->imagePath !== null ? Storage::disk('public')->url($node->imagePath) : null,
                'count' => $counts[$node->id] ?? 0,
            ];
        }

        return $tiles;
    }
}
