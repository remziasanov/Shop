<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CustomerGroup;
use App\Models\Import;
use App\Services\Access\CatalogAccess;
use App\Services\Access\ViewerContext;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Warms the facet cache of the category pages WITHOUT filters (FL-11, FL-12): the page a visitor opens first. The
 * unfiltered facets are the slowest to build (the whole category is counted), and without warming the first visitor
 * after the cache key expired or the catalog version changed waits for them.
 *
 * Works through {@see FacetService::forCategory()}, so it writes exactly the key a real page request reads and an
 * entry that is still cached costs one file read. Warmed for the viewers that make up most of the traffic: «Гость» and
 * «Розница»; the other groups are cached on demand. The work is a flat list of (group, category) pairs processed in
 * budgeted steps: the caller continues from the returned offset (every queued job stays far below 40 s, TZ section 2).
 *
 * Only the categories where the unfiltered facets can be slow are warmed: at least {@see MIN_PRODUCTS} visible products
 * in the subtree (a smaller category builds its facets well under 100 ms), the largest first, at most
 * {@see MAX_CATEGORIES} per pass (D-165).
 *
 * The class also owns the cheap bookkeeping that keeps the warming from loading a shared host ({@see shouldStart()}):
 * one chain at a time, a «settled» catalog version, and the record of the last completed pass.
 */
final class FacetWarmer
{
    /** Subtree size from which the unfiltered facets are worth warming; below it they build in well under 100 ms. */
    public const MIN_PRODUCTS = 1000;

    /** At most this many categories per pass (times the two warmed groups). */
    public const MAX_CATEGORIES = 100;

    /** The catalog version must be at least this old before a pass starts, so bursts of changes do not trigger it. */
    public const SETTLE_SECONDS = 180;

    public const CHAIN_KEY = 'facets:warm:chain';

    public const LAST_PASS_KEY = 'facets:warm:last-pass';

    /** Safety net: a chain flag of a dead worker disappears after this many seconds. */
    public const CHAIN_TTL_SECONDS = 300;

    public function __construct(
        private readonly ViewerContext $viewer,
        private readonly CatalogAccess $access,
        private readonly CategoryTree $tree,
        private readonly StorefrontFilterSets $sets,
        private readonly FacetService $facets,
        private readonly CatalogSettings $settings,
        private readonly int $minProducts = self::MIN_PRODUCTS,
        private readonly int $maxCategories = self::MAX_CATEGORIES,
    ) {}

    /**
     * Whether `shop:warm-facets` must queue a new chain now. Cheap checks first; false in the usual case.
     */
    public function shouldStart(): bool
    {
        $minutes = $this->settings->facets_cache_minutes;

        if ($minutes <= 0 || Cache::has(self::CHAIN_KEY)) {
            return false;
        }

        $version = CatalogVersion::current();

        if (! $this->isSettled($version)) {
            return false;
        }

        $last = Cache::get(self::LAST_PASS_KEY);
        $expiring = ! is_array($last)
            || ($last['version'] ?? null) !== $version
            || (int) ($last['at'] ?? 0) <= now()->getTimestamp() - max(1, $minutes - 1) * 60;

        return $expiring && ! Import::anyRunning();
    }

    /**
     * A version token is a microsecond timestamp ({@see CatalogVersion::bump()}); one that cannot be parsed counts as settled.
     */
    public function isSettled(string $version): bool
    {
        if (preg_match('/^(\d{13,})a?$/', $version, $matches) !== 1) {
            return true;
        }

        return now()->getTimestamp() - intdiv((int) $matches[1], 1_000_000) >= self::SETTLE_SECONDS;
    }

    public function markChainActive(): void
    {
        Cache::put(self::CHAIN_KEY, true, self::CHAIN_TTL_SECONDS);
    }

    /**
     * The chain ended normally: remember which version was warmed and when.
     */
    public function finishChain(string $version): void
    {
        Cache::forget(self::CHAIN_KEY);
        Cache::put(self::LAST_PASS_KEY, ['version' => $version, 'at' => now()->getTimestamp()], 86_400);
    }

    /**
     * The chain stops without a record (the version changed meanwhile); the next settled minute starts a fresh one.
     */
    public function abortChain(): void
    {
        Cache::forget(self::CHAIN_KEY);
    }

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
     * The (group, category) pairs of a pass: for each warmed group the largest visible categories that have a filter set.
     *
     * @return list<array{0: CustomerGroup, 1: int}>
     */
    public function pairs(): array
    {
        $ids = $this->slowCandidates();
        $pairs = [];

        foreach ([CustomerGroup::guest(), CustomerGroup::retail()] as $group) {
            $access = $this->access->for($group);

            foreach ($ids as $id) {
                if ($access->canSeeCategory($id)) {
                    $pairs[] = [$group, $id];
                }
            }
        }

        return $pairs;
    }

    /**
     * Visible categories with a filter set and at least `minProducts` visible products in the subtree, largest first,
     * capped. The counts come from one grouped query ({@see CategoryTree::productCountsWithDescendants()}).
     *
     * @return list<int>
     */
    private function slowCandidates(): array
    {
        $counts = array_filter(
            $this->tree->productCountsWithDescendants(true),
            fn (int $count): bool => $count >= $this->minProducts,
        );

        $ids = array_keys($counts);
        usort($ids, fn (int $a, int $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        $result = [];

        foreach ($ids as $id) {
            if (count($result) >= $this->maxCategories) {
                break;
            }

            if ($this->tree->isVisible($id) && ! $this->sets->for($id)->isEmpty()) {
                $result[] = $id;
            }
        }

        return $result;
    }
}
