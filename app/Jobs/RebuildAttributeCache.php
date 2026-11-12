<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Catalog\AttributeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Rebuilds `products.attributes` (the JSON display cache) of up to {@see self::MAX_IDS} products from the
 * normalized tables (FL-20, 20.2). Idempotent; 500 products take a few seconds, well under the 40 s job limit.
 */
class RebuildAttributeCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_IDS = 500;

    public int $tries = 3;

    public int $timeout = 40;

    /**
     * @param  array<int>  $productIds
     */
    public function __construct(public array $productIds) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(AttributeSyncService $sync): void
    {
        // Forced: the caller changed the tables for exactly these products, the JSON must follow.
        $sync->syncProducts($this->productIds, true);
    }
}
