<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesMode;
use App\Models\Product;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CategoryTree;
use App\Settings\SalesSettings;
use Illuminate\Contracts\Cache\Repository;

/**
 * Whether the cart exists anywhere in the shop (SF-01, SF-03): the shop default mode, any category or any
 * active product allows the cart. The flag is cached (10 minutes) and dropped when sales settings, categories
 * or the sales mode of a product change, so the header icon appears and disappears without waiting.
 */
final class CartAvailability
{
    public const CACHE_KEY = 'storefront.cart_enabled';

    private const TTL_SECONDS = 600;

    public function __construct(
        private readonly Repository $cache,
        private readonly SalesSettings $settings,
        private readonly CategoryTree $tree,
        private readonly ViewerContext $viewer,
    ) {}

    public function enabled(): bool
    {
        $access = $this->viewer->access();

        // The shop-wide flag is cached; the viewer's group may still take the cart away (CG-07, CG-06).
        return (bool) $this->cache->remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): bool => $this->compute())
            && $access->showsPrices()
            && $access->allowsMode(SalesMode::Cart);
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    private function compute(): bool
    {
        if ($this->allowsCart($this->settings->default_mode)) {
            return true;
        }

        foreach ($this->tree->nodes() as $node) {
            if ($this->allowsCart($node->salesModeEnum())) {
                return true;
            }
        }

        return Product::query()
            ->where('is_active', true)
            ->whereIn('sales_mode', [SalesMode::Cart->value, SalesMode::Both->value])
            ->exists();
    }

    private function allowsCart(?SalesMode $mode): bool
    {
        return $mode === SalesMode::Cart || $mode === SalesMode::Both;
    }
}
