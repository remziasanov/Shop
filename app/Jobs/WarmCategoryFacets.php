<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\FacetWarmer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the cached facets of the category pages without filters (FL-11, FL-12, D-164). Works in budgeted steps of
 * about 30 s (a job lives at most 40 s) and continues as a new job from where it stopped. A lock keeps two chains from
 * working at once; `shop:warm-facets` starts a chain every minute and does nothing while all entries are cached.
 */
class WarmCategoryFacets implements ShouldQueue
{
    use Queueable;

    public const BUDGET_SECONDS = 30.0;

    public int $tries = 1;

    public int $timeout = 40;

    public function __construct(public readonly int $offset = 0) {}

    public function handle(FacetWarmer $warmer): void
    {
        $lock = Cache::lock('shop:warm-facets', 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $next = $warmer->warm($this->offset, self::BUDGET_SECONDS);
        } finally {
            $lock->release();
        }

        if ($next !== null) {
            self::dispatch($next);
        }
    }
}
