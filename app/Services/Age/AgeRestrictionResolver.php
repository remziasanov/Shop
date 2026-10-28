<?php

declare(strict_types=1);

namespace App\Services\Age;

use App\Enums\AgeRestriction;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\CategoryNode;
use App\Services\Catalog\CategoryTree;

/**
 * Effective age restriction (TZ 16.5, AG-01): the product's own value, otherwise the value of the nearest
 * category (the category itself, then its parents), otherwise none.
 *
 * Everything is answered from the cached category tree: walking up a branch is at most {@see Category::MAX_DEPTH}
 * array look-ups, so a list of products costs no SQL and needs no per-request map that could go stale after a
 * category edit. Products must be loaded with their `age_restriction` and `category_id` columns.
 */
final class AgeRestrictionResolver
{
    public function __construct(private readonly CategoryTree $tree) {}

    public function forProduct(Product $product): ?AgeRestriction
    {
        return $product->age_restriction ?? $this->forCategoryId($product->category_id);
    }

    /**
     * Level of a category: its own value, otherwise the nearest parent's.
     */
    public function forCategoryId(?int $categoryId): ?AgeRestriction
    {
        return $this->categorySource($categoryId)?->ageRestriction;
    }

    public function forCategory(Category|CategoryNode $category): ?AgeRestriction
    {
        return $this->forCategoryId($category->id);
    }

    /**
     * The category that sets the restriction of the given one (itself or the nearest parent), or null.
     */
    public function categorySource(?int $categoryId): ?CategoryNode
    {
        $node = $categoryId !== null ? $this->tree->node($categoryId) : null;
        $guard = 0;

        while ($node !== null && $guard++ < 2 * Category::MAX_DEPTH) {
            if ($node->ageRestriction !== null) {
                return $node;
            }

            $node = $node->parentId !== null ? $this->tree->node($node->parentId) : null;
        }

        return null;
    }

    /**
     * Level of a product and where it comes from (admin hint, AG-12).
     *
     * @return array{level: AgeRestriction|null, source: 'product'|'category'|null, category: CategoryNode|null}
     */
    public function describe(Product $product): array
    {
        return $this->describeFor($product->age_restriction, $product->category_id);
    }

    /**
     * Same for a form state that is not saved yet. A product passes its own value and its category, a category
     * passes its own value and its parent.
     *
     * @return array{level: AgeRestriction|null, source: 'product'|'category'|null, category: CategoryNode|null}
     */
    public function describeFor(?AgeRestriction $own, ?int $inheritFromCategoryId): array
    {
        if ($own !== null) {
            return ['level' => $own, 'source' => 'product', 'category' => null];
        }

        $source = $this->categorySource($inheritFromCategoryId);

        return $source === null
            ? ['level' => null, 'source' => null, 'category' => null]
            : ['level' => $source->ageRestriction, 'source' => 'category', 'category' => $source];
    }

    /**
     * The strictest level among the products (AG-11: the age asked at checkout).
     *
     * @param  iterable<Product>  $products
     */
    public function strictest(iterable $products): ?AgeRestriction
    {
        $strictest = null;

        foreach ($products as $product) {
            $strictest = AgeRestriction::max($strictest, $this->forProduct($product));
        }

        return $strictest;
    }

    /**
     * Counters for the settings page (AG-14).
     *
     * @return array{products_own: int, products_effective: int, categories_own: int, categories_effective: int}
     */
    public function counts(): array
    {
        $restricted = $this->restrictedCategoryIds();
        $own = 0;

        foreach ($this->tree->nodes() as $node) {
            if ($node->ageRestriction !== null) {
                $own++;
            }
        }

        return [
            'products_own' => Product::query()->whereNotNull('age_restriction')->count(),
            'products_effective' => Product::query()
                ->where(function ($query) use ($restricted): void {
                    $query->whereNotNull('age_restriction')->orWhereIn('category_id', $restricted);
                })
                ->count(),
            'categories_own' => $own,
            'categories_effective' => count($restricted),
        ];
    }

    /**
     * Ids of the categories whose effective level is set (own or inherited).
     *
     * @return list<int>
     */
    public function restrictedCategoryIds(): array
    {
        $ids = [];

        foreach ($this->tree->nodes() as $id => $node) {
            if ($this->forCategoryId($id) !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
