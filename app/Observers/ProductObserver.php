<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\RedirectService;
use App\Services\Catalog\SlugService;
use App\Services\Media\ImageProcessor;
use App\Services\Pricing\PriceHistoryRecorder;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Search\SearchNormalizer;
use App\Services\Variants\SkuGuard;

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
        private readonly SkuGuard $skus,
    ) {}

    public function saving(Product $product): void
    {
        if (! $product->exists || $product->isDirty('slug') || trim((string) $product->slug) === '') {
            $product->slug = $this->slugs->resolve('products', $product->slug, (string) $product->name, $product->id);
        }

        if (! $product->exists || $product->isDirty(['name', 'sku', 'brand']) || $product->search_text === '') {
            $product->search_text = $this->search->buildSearchText((string) $product->name, (string) $product->sku, (string) $product->brand);
        }

        // VR-04: an article is unique among products and variants (the unique index covers products only).
        if (! $product->exists || $product->isDirty('sku')) {
            $this->skus->assertAvailable((string) $product->sku, $product);
        }

        // A card with variants takes its prices from them (see saved()).
        if (! $this->prices->isVariantCard($product) && $this->prices->needsUpdate($product)) {
            $this->prices->apply($product);
        }
    }

    public function saved(Product $product): void
    {
        // VR-07: the variants inherit the card's prices and rule scope, so a change of those recalculates them.
        if ($this->prices->variantsNeedUpdate($product)) {
            $this->prices->recalculateVariantsOf($product);
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
     * VR-27: moving a card to the trash takes its variants along; they get the very same deletion time, so that
     * {@see restoring()} brings back exactly them (variants deleted earlier stay deleted).
     */
    public function deleted(Product $product): void
    {
        if ($product->isForceDeleting() || ! $product->has_variants || $product->deleted_at === null) {
            return;
        }

        ProductVariant::query()->where('product_id', $product->id)->toBase()->update(['deleted_at' => $product->deleted_at]);
    }

    public function restoring(Product $product): void
    {
        if (! $product->has_variants || $product->deleted_at === null) {
            return;
        }

        ProductVariant::onlyTrashed()
            ->where('product_id', $product->id)
            ->where('deleted_at', $product->deleted_at)
            ->toBase()
            ->update(['deleted_at' => null]);
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
