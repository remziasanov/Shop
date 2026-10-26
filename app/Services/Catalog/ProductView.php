<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\SalesMode;
use App\Models\Product;
use App\Services\Sales\ProductActions;

/**
 * Display data of one product for the storefront (cards, product page, suggestions).
 */
final readonly class ProductView
{
    /**
     * @param  list<array{key: string, text: string}>  $labels
     * @param  array{text: string, state: string}|null  $availability  state: in|low|out
     * @param  list<array{key: string, label: string, primary: bool, disabled: bool}>  $actions  buttons to render (key: cart|reservation|inquiry|unavailable)
     * @param  array{src: string, srcset: string, width: int, height: int, alt: string}|null  $image
     */
    public function __construct(
        public Product $product,
        public string $url,
        public bool $priceOnRequest,
        public string $price,
        public ?string $oldPrice,
        public ?int $discount,
        public array $labels,
        public ?array $availability,
        public SalesMode $mode,
        public array $actions,
        public ?array $image,
        public ?string $placeholderUrl,
        public ProductActions $productActions,
    ) {}
}
