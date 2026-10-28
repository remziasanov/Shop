<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesMode;
use App\Models\Product;
use App\Services\Access\ViewerContext;
use App\Settings\SalesSettings;

/**
 * Effective sales mode of a product adjusted by stock (TZ 5.6). Pure: depends only on the product's
 * current attributes, the resolved mode (clamped by the viewer's customer group, CG-07) and the sales settings, and runs
 * no queries of its own. Storefront only: admin code uses {@see SalesModeResolver} directly.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly SalesModeResolver $resolver,
        private readonly SalesSettings $settings,
        private readonly ViewerContext $viewer,
    ) {}

    public function actionsFor(Product $product): ProductActions
    {
        $access = $this->viewer->access();
        $mode = $this->resolver->resolve($product, $access)->mode;
        $showPrice = ! $product->price_on_request && $access->showsPrices();
        $hasCart = $mode === SalesMode::Cart || $mode === SalesMode::Both;
        $hasReservation = $mode === SalesMode::Reservation || $mode === SalesMode::Both;

        if ($mode === SalesMode::Showcase) {
            return new ProductActions($mode, false, false, false, false, false, null, $showPrice);
        }

        if ($mode === SalesMode::Inquiry) {
            return new ProductActions($mode, false, false, true, false, false, null, $showPrice);
        }

        $available = $this->settings->stock_tracking ? $product->availableQty() : null;

        if ($available === null) {
            // Stock is not tracked: no limits.
            return new ProductActions($mode, $hasCart, $hasReservation, false, false, false, null, $showPrice);
        }

        if ($available > 0) {
            return new ProductActions(
                $mode,
                $hasCart,
                $hasReservation,
                false,
                false,
                false,
                $this->settings->allow_backorder ? null : $available,
                $showPrice,
                $available,
            );
        }

        if ($this->settings->allow_backorder && $hasCart) {
            return new ProductActions($mode, true, false, false, true, false, null, $showPrice, 0);
        }

        return new ProductActions($mode, false, false, true, false, true, 0, $showPrice, 0);
    }
}
