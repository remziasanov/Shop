<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Product;
use App\Support\Money;

/**
 * Everything {@see PriceCalculator} needs to know about a product. Money is integer kopecks.
 *
 * `categoryPathIds` holds the product's category and all its ancestors; the caller derives it from the
 * category tree so that the calculator stays free of any lookups.
 */
final readonly class PriceInput
{
    /**
     * @param  list<int>  $categoryPathIds  category id + ancestor ids (an empty list means just $categoryId)
     */
    public function __construct(
        public ?int $productId,
        public ?int $categoryId,
        public ?string $brand,
        public bool $isFeatured,
        public bool $isNew,
        public int $basePrice,
        public ?int $costPrice = null,
        public ?int $compareAtPrice = null,
        public ?int $fixedPrice = null,
        public array $categoryPathIds = [],
    ) {}

    /**
     * @param  list<int>  $categoryPathIds  category id + ancestor ids (see CategoryTree::selfAndAncestorIds())
     */
    public static function fromProduct(Product $product, array $categoryPathIds = []): self
    {
        return new self(
            productId: $product->exists ? $product->id : null,
            categoryId: (int) $product->category_id ?: null,
            brand: $product->brand !== null && trim($product->brand) !== '' ? $product->brand : null,
            isFeatured: (bool) $product->is_featured,
            isNew: (bool) $product->is_new,
            basePrice: self::kopecks($product->base_price) ?? 0,
            costPrice: self::kopecks($product->cost_price),
            compareAtPrice: self::kopecks($product->compare_at_price),
            fixedPrice: self::kopecks($product->fixed_price),
            categoryPathIds: $categoryPathIds,
        );
    }

    /**
     * Category ids that count as "the product's category" for rule matching.
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        $ids = $this->categoryPathIds;

        if ($this->categoryId !== null && ! in_array($this->categoryId, $ids, true)) {
            $ids[] = $this->categoryId;
        }

        return $ids;
    }

    private static function kopecks(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::fromRubles((string) $value);
    }
}
