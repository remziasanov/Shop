<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Pricing\RecalculationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Recalculates final prices of up to {@see self::MAX_IDS} products (PR-02, D-027). Idempotent: the result
 * depends only on the current rules and product data, so a retry or a duplicate run is harmless. A chunk
 * of 500 products takes well under the 40 s job limit (TZ section 2).
 */
class RecalculatePrices implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public function handle(ProductPriceUpdater $prices, RecalculationProgress $progress): void
    {
        $prices->recalculate($this->productIds);

        $progress->advance(count($this->productIds));
    }

    /**
     * Give up on the chunk, but let the banner finish.
     */
    public function failed(?Throwable $exception): void
    {
        app(RecalculationProgress::class)->advance(count($this->productIds));
    }
}
