<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CustomerGroup;
use App\Services\Access\CatalogAccess;
use App\Services\Access\ViewerContext;
use App\Settings\CatalogSettings;

/**
 * Warms the facet cache of the category pages WITHOUT filters (FL-11, FL-12): the page a visitor opens first. The
 * unfiltered facets are the slowest to build (the whole category is counted), and without warming the first visitor
 * after the cache key expired or the catalog version changed waits for them.
 *
 * Works through {@see FacetService::forCategory()}, so it writes exactly the key a real page request reads and an
 * entry that is still cached costs one file read. Warmed for the viewers that make up most of the traffic: «Гость» and
 * «Розница»; the other groups are cached on demand. The work is a flat list of (group, category) pairs processed in
 * budgeted steps: the caller continues from the returned offset (every queued job stays far below 40 s, TZ section 2).
 */
final class FacetWarmer
{
    public function __construct(
        private readonly ViewerContext $viewer,
        private readonly CatalogAccess $access,
        private readonly CategoryTree $tree,
        private readonly StorefrontFilterSets $sets,
        private readonly FacetService $facets,
        private readonly CatalogSettings $settings,
    ) {}

    /**
     * Warms the pairs from `$offset` on until the budget is used up.
     *
     * @param  float  $budgetSeconds  stop starting new pairs after this time
     * @return int|null offset to continue from, null when the list is done (or the facet cache is off)
     */
    public function warm(int $offset = 0, float $budgetSeconds = 30.0): ?int
    {
        if ($this->settings->facets_cache_minutes <= 0) {
            return null;
        }

        $started = microtime(true);
        $pairs = $this->pairs();

        try {
            for ($i = $offset; $i < count($pairs); $i++) {
                if (microtime(true) - $started >= $budgetSeconds) {
                    return $i;
                }

                [$group, $categoryId] = $pairs[$i];
                $this->viewer->actingAs($group);
                $filters = (new CatalogFilters)->restrictedTo($this->viewer->access());
                $this->facets->forCategory($categoryId, $this->tree->descendantIds($categoryId), $filters);
            }
        } finally {
            $this->viewer->actingAs(null);
        }

        return null;
    }

    /**
     * The visible categories that have a filter set, for each warmed group.
     *
     * @return list<array{0: CustomerGroup, 1: int}>
     */
    public function pairs(): array
    {
        $pairs = [];

        foreach ([CustomerGroup::guest(), CustomerGroup::retail()] as $group) {
            $access = $this->access->for($group);

            foreach ($this->tree->visibleCategoryIds() as $id) {
                if ($access->canSeeCategory($id) && ! $this->sets->for($id)->isEmpty()) {
                    $pairs[] = [$group, $id];
                }
            }
        }

        return $pairs;
    }
}
