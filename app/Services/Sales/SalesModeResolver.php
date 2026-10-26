<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesMode;
use App\Enums\SalesModeSource;
use App\Models\Product;
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
 * Categories come from the cached tree, so resolving a page of products costs no queries.
 * Stock-based adjustments (out of stock, backorder) are added in stage 4.
 */
final class SalesModeResolver
{
    public function __construct(
        private readonly CategoryTree $tree,
        private readonly SalesSettings $settings,
    ) {}

    public function resolve(Product $product): ResolvedSalesMode
    {
        return $this->resolveFor($product->sales_mode, $product->category_id, $product->price_on_request);
    }

    /**
     * Same logic for values that are not saved yet (live hints in the product form).
     */
    public function resolveFor(?SalesMode $productMode, ?int $categoryId, bool $priceOnRequest = false): ResolvedSalesMode
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
