<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Variants\VariantLabel;

/**
 * Product search of the order editor (OE-02, 24.3): active products by name, article or barcode; a card with
 * variants is listed by its active variants, each with its label and article. Options are keyed `productId:variantId`
 * (variant id 0 for a product without variants).
 */
final class OrderItemSearch
{
    public function __construct(private readonly VariantLabel $labels) {}

    /**
     * @return array<string, string> option key => «Название (модификация) · артикул»
     */
    public function options(string $term, int $limit = 30): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $products = Product::query()->where('is_active', true)->search($term)->limit($limit)->get();
        $variants = ProductVariant::query()
            ->where('is_active', true)
            ->whereIn('product_id', $products->where('has_variants', true)->pluck('id')->all())
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();
        $labels = $this->labels->forMany($variants);
        $options = [];

        foreach ($products as $product) {
            if (! $product->has_variants) {
                $options[$product->id.':0'] = $product->name.' · '.$product->sku;

                continue;
            }

            foreach ($variants->where('product_id', $product->id) as $variant) {
                $label = $labels[$variant->id] ?? '';
                $options[$product->id.':'.$variant->id] = $product->name.($label === '' ? '' : ' ('.$label.')').' · '.$variant->sku;
            }
        }

        return array_slice($options, 0, $limit, true);
    }

    /**
     * Splits an option key into the product and the variant id.
     *
     * @return array{0: int, 1: int|null}|null
     */
    public static function parse(string $key): ?array
    {
        if (! preg_match('/^(\d+):(\d+)$/', $key, $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2] === 0 ? null : (int) $m[2]];
    }
}
