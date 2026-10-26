<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * One page of products plus the brands available for the brand filter.
 */
final readonly class CatalogResult
{
    /**
     * @param  LengthAwarePaginator<int, Product>  $products
     * @param  list<string>  $brands  brands present in the selection before the brand filter
     */
    public function __construct(
        public LengthAwarePaginator $products,
        public array $brands,
    ) {}
}
