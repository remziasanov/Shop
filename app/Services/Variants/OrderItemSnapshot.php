<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Snapshot of a sold unit for `order_items` (VR-12): the product or one of its variants with its article, barcode and
 * label «Цвет: красный, Размер: M». Prices and quantity are added by the caller.
 */
final class OrderItemSnapshot
{
    public function __construct(private readonly VariantLabel $labels) {}

    /**
     * @return array{product_id: int, variant_id: int|null, sku: string, variant_label: string|null, barcode: string|null, name: string, unit: string}
     */
    public function for(Product $product, ?ProductVariant $variant = null): array
    {
        return [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'sku' => $variant !== null ? $variant->sku : $product->sku,
            'variant_label' => $variant !== null ? ($this->labels->for($variant) ?: null) : null,
            'barcode' => $variant?->barcode,
            'name' => Str::limit($product->name, 500, ''),
            'unit' => $product->unit,
        ];
    }
}
