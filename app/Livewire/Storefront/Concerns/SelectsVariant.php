<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * The variant chosen in the picker of the product page (VR-09, VR-14) for the cart, reservation and inquiry components.
 * The id is set from the browser without a request; the server checks it again whenever it is used (active, of this
 * card), so a forged id can only fail.
 */
trait SelectsVariant
{
    /** Selected variant id; 0 = none. */
    public int $variantId = 0;

    protected function selectedVariantId(): ?int
    {
        return $this->variantId > 0 ? $this->variantId : null;
    }

    /**
     * What the buttons and limits refer to: the product, or the selected active variant of a card with variants (null
     * when such a card has no valid selection).
     */
    protected function unitOf(Product $product): Product|ProductVariant|null
    {
        if (! $product->has_variants) {
            return $product;
        }

        $variant = $this->selectedVariantId() !== null
            ? ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->find($this->variantId)
            : null;

        return $variant?->setRelation('product', $product);
    }

    /**
     * The message for a card with variants without a valid selection.
     */
    protected function variantError(Product $product): string
    {
        return __($this->selectedVariantId() === null ? 'variant_storefront.errors.required' : 'variant_storefront.errors.unavailable', ['name' => $product->name]);
    }
}
