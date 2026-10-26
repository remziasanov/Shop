<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Concerns;

use App\Enums\CatalogSort;
use App\Http\Requests\CatalogFilterRequest;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\ProductPresenter;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Data shared by every product listing page (catalog, category, search): products, brands, filters, sort.
 */
trait BuildsListing
{
    /**
     * @param  list<int>|null  $categoryIds
     * @param  array<string, mixed>  $extraQuery
     * @return array<string, mixed>
     */
    private function listing(
        CatalogFilterRequest $request,
        ?array $categoryIds = null,
        ?string $search = null,
        array $extraQuery = [],
    ): array {
        $filters = $request->filters();
        $result = app(CatalogQuery::class)->paginate($filters, $categoryIds, $search, $extraQuery);

        return [
            'cards' => app(ProductPresenter::class)->many($result->products),
            'paginator' => $result->products,
            'brands' => $result->brands,
            'filters' => $filters,
            'sortOptions' => CatalogSort::options(),
            'gridColumns' => app(AppearanceSettings::class)->grid_columns === 3 ? 3 : 4,
            'formAction' => $request->url(),
            'extraQuery' => $extraQuery,
        ];
    }

    /**
     * Category tiles (SF-10, SF-11) from the cached tree: active categories only.
     *
     * @param  list<CategoryNode>  $nodes
     * @return list<array{name: string, url: string, image: string|null, count: int}>
     */
    private function tiles(array $nodes): array
    {
        $counts = app(CategoryTree::class)->productCountsWithDescendants(true);
        $tiles = [];

        foreach ($nodes as $node) {
            if (! $node->isActive) {
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
