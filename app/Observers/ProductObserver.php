<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Services\Catalog\RedirectService;
use App\Services\Catalog\SlugService;
use App\Services\Media\ImageProcessor;
use App\Services\Pricing\PriceHistoryRecorder;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Search\SearchNormalizer;

/**
 * Thin glue: slug, redirects, search text, price columns, price history and photo files.
 */
final class ProductObserver
{
    public function __construct(
        private readonly SlugService $slugs,
        private readonly RedirectService $redirects,
        private readonly SearchNormalizer $search,
        private readonly ProductPriceUpdater $prices,
        private readonly PriceHistoryRecorder $history,
        private readonly ImageProcessor $images,
    ) {}

    public function saving(Product $product): void
    {
        if (! $product->exists || $product->isDirty('slug') || trim((string) $product->slug) === '') {
            $product->slug = $this->slugs->resolve('products', $product->slug, (string) $product->name, $product->id);
        }

        if (! $product->exists || $product->isDirty(['name', 'sku', 'brand']) || $product->search_text === '') {
            $product->search_text = $this->search->buildSearchText((string) $product->name, (string) $product->sku, (string) $product->brand);
        }

        if ($this->prices->needsUpdate($product)) {
            $this->prices->apply($product);
        }
    }

    public function updated(Product $product): void
    {
        $this->history->recordUpdate($product);

        if ($product->wasChanged('slug')) {
            $this->redirects->record('/product/'.$product->getOriginal('slug'), '/product/'.$product->slug);
        }
    }

    /**
     * Permanent deletion removes the photo files (AD-44); soft deletion keeps them.
     */
    public function forceDeleted(Product $product): void
    {
        $this->images->deleteProductDirectory($product);
        $this->redirects->forgetTarget('/product/'.$product->slug);
    }
}
