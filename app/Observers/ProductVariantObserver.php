<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProductVariant;
use App\Services\Pricing\PriceHistoryRecorder;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Variants\VariantAggregator;

/**
 * Thin glue for variants: price columns (VR-07), price history (VR-22) and the card aggregates (VR-06, VR-07).
 * Stock is written by StockService with `saveQuietly()`, which rolls the aggregates up itself.
 */
final class ProductVariantObserver
{
    public function __construct(
        private readonly ProductPriceUpdater $prices,
        private readonly PriceHistoryRecorder $history,
        private readonly VariantAggregator $aggregator,
    ) {}

    public function saving(ProductVariant $variant): void
    {
        if ($this->prices->variantNeedsUpdate($variant)) {
            $this->prices->applyToVariant($variant);
        }
    }

    public function updated(ProductVariant $variant): void
    {
        $this->history->recordVariantUpdate($variant);
    }

    public function saved(ProductVariant $variant): void
    {
        if ($variant->wasRecentlyCreated || $variant->wasChanged(['is_active', 'final_price', 'display_old_price', 'stock_qty', 'reserved_qty', 'deleted_at'])) {
            $this->aggregator->refresh($variant->product_id);
        }
    }

    public function deleted(ProductVariant $variant): void
    {
        if (! $variant->isForceDeleting()) {
            $this->aggregator->refresh($variant->product_id);
        }
    }

    public function restored(ProductVariant $variant): void
    {
        $this->aggregator->refresh($variant->product_id);
    }
}
