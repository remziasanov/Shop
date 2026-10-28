<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesMode;
use App\Enums\SalesModeSource;
use App\Models\Product;
use App\Services\Access\AccessSet;
use App\Services\Catalog\CategoryTree;
use App\Settings\SalesSettings;

/**
 * Effective sales mode of a product (TZ 5.6, D-016). Order of precedence:
 *
 *  1. "price on request" flag  => inquiry
 *  2. the product's own mode
 *  3. the nearest category (own, then parents) with a mode
 *  4. the shop default (SalesSettings::default_mode)
 *
 * Storefront callers pass the viewer's {@see AccessSet}: the result is then clamped by the customer group (CG-06, CG-07,
 * TZ 25.6). Admin code passes nothing and sees the unclamped mode.
 *
 * Categories come from the cached tree, so resolving a page of products costs no queries.
 * Stock-based adjustments (out of stock, backorder) are added in stage 4.
 */
final class SalesModeResolver
{
    public function __construct(
        private readonly CategoryTree $tree,
        private readonly SalesSettings $settings,
    ) {}

    public function resolve(Product $product, ?AccessSet $access = null): ResolvedSalesMode
    {
        return $this->resolveFor($product->sales_mode, $product->category_id, $product->price_on_request, $access);
    }

    /**
     * Same logic for values that are not saved yet (live hints in the product form).
     */
    public function resolveFor(?SalesMode $productMode, ?int $categoryId, bool $priceOnRequest = false, ?AccessSet $access = null): ResolvedSalesMode
    {
        $resolved = $this->resolveUnclamped($productMode, $categoryId, $priceOnRequest);

        return $access === null ? $resolved : $this->clamp($resolved, $access);
    }

    /**
     * Narrows a mode to what the customer group allows (TZ 25.6, CG-07): a mode the group may not use becomes an inquiry
     * when that is allowed, otherwise a showcase; hidden prices (CG-06) take away cart and reservation the same way.
     */
    public function clamp(ResolvedSalesMode $resolved, AccessSet $access): ResolvedSalesMode
    {
        $mode = $resolved->mode;
        $fallback = $access->allowsMode(SalesMode::Inquiry) ? SalesMode::Inquiry : SalesMode::Showcase;

        if ($mode === SalesMode::Both) {
            $cart = $access->allowsMode(SalesMode::Cart);
            $reservation = $access->allowsMode(SalesMode::Reservation);
            $mode = match (true) {
                $cart && $reservation => SalesMode::Both,
                $cart => SalesMode::Cart,
                $reservation => SalesMode::Reservation,
                default => $fallback,
            };
        } elseif (! $access->allowsMode($mode)) {
            $mode = $mode === SalesMode::Inquiry ? SalesMode::Showcase : $fallback;
        }

        if (! $access->showsPrices() && in_array($mode, [SalesMode::Cart, SalesMode::Reservation, SalesMode::Both], true)) {
            $mode = $fallback;
        }

        return $mode === $resolved->mode ? $resolved : new ResolvedSalesMode($mode, SalesModeSource::Group);
    }

    private function resolveUnclamped(?SalesMode $productMode, ?int $categoryId, bool $priceOnRequest): ResolvedSalesMode
    {
        if ($priceOnRequest) {
            return new ResolvedSalesMode(SalesMode::Inquiry, SalesModeSource::PriceOnRequest);
        }

        if ($productMode !== null) {
            return new ResolvedSalesMode($productMode, SalesModeSource::Product);
        }

        if ($categoryId !== null) {
            // Nearest first: the category itself, then its parents.
            foreach (array_reverse($this->tree->breadcrumbs($categoryId)) as $node) {
                $mode = $node->salesModeEnum();

                if ($mode !== null) {
                    return new ResolvedSalesMode($mode, SalesModeSource::Category, $node->toModel());
                }
            }
        }

        return new ResolvedSalesMode($this->settings->default_mode, SalesModeSource::Shop);
    }
}
