<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\FacetWarmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the cached facets of the large category pages without filters (FL-11, FL-12, D-165). Works in budgeted steps of
 * about 30 s (a job lives at most 40 s) and continues as a new job from where it stopped. A lock keeps two jobs from
 * working at once. `shop:warm-facets` starts a chain only when {@see FacetWarmer::shouldStart()} agrees; the chain
 * carries the catalog version it warms and stops when the version changes meanwhile.
 */
class WarmCategoryFacets implements ShouldQueue
{
    use Queueable;

    public const BUDGET_SECONDS = 30.0;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(
        public readonly int $offset = 0,
        public readonly ?string $version = null,
        public readonly float $budgetSeconds = self::BUDGET_SECONDS,
    ) {}

    public function handle(FacetWarmer $warmer): void
    {
        $lock = Cache::lock('shop:warm-facets', 60);

        if (! $lock->get()) {
            return;
        }

        try {
            CatalogVersion::reset();
            $version = CatalogVersion::current();

            if ($this->version !== null && $this->version !== $version) {
                $warmer->abortChain();

                return;
            }

            $next = $warmer->warm($this->offset, $this->budgetSeconds);
        } finally {
            $lock->release();
        }

        if ($next === null) {
            $warmer->finishChain($version);

            return;
        }

        $warmer->markChainActive();
        self::dispatch($next, $version, $this->budgetSeconds);
    }
}
