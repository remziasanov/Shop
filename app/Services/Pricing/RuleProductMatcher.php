<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceRuleScope;
use App\Enums\ProductFlag;
use App\Services\Catalog\CategoryTree;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Finds the products inside a price rule's scope with plain SQL (portable: no window functions, no CTE).
 * Soft-deleted products are never returned. Works on {@see PriceRuleData} snapshots, so unsaved rules
 * (previews) and the state of a rule before a change can be matched too.
 */
final class RuleProductMatcher
{
    private const CHUNK = 1000;

    public function __construct(private readonly CategoryTree $categories) {}

    /**
     * Ids of the products matching the rule, ascending.
     *
     * @return LazyCollection<int, int>
     */
    public function productIds(PriceRuleData $rule): LazyCollection
    {
        return $this->ids($this->query()->where(fn (Builder $q) => $this->applyScope($q, $rule)));
    }

    public function count(PriceRuleData $rule): int
    {
        return $this->query()->where(fn (Builder $q) => $this->applyScope($q, $rule))->count();
    }

    /**
     * Products matching either version of a changed rule (union, unique, ascending). A null side means
     * the rule did not exist before / does not exist anymore.
     *
     * @return LazyCollection<int, int>
     */
    public function affectedIds(?PriceRuleData $before, ?PriceRuleData $after): LazyCollection
    {
        $rules = array_values(array_filter([$before, $after]));

        if ($rules === []) {
            return LazyCollection::empty();
        }

        $query = $this->query()->where(function (Builder $q) use ($rules): void {
            foreach ($rules as $rule) {
                $q->orWhere(fn (Builder $inner) => $this->applyScope($inner, $rule));
            }
        });

        return $this->ids($query);
    }

    private function query(): Builder
    {
        return DB::table('products')->whereNull('products.deleted_at');
    }

    /**
     * @return LazyCollection<int, int>
     */
    private function ids(Builder $query): LazyCollection
    {
        return $query
            ->select('products.id')
            ->lazyById(self::CHUNK, 'products.id', 'id')
            ->map(fn (object $row): int => (int) $row->id)
            ->values();
    }

    private function applyScope(Builder $query, PriceRuleData $rule): void
    {
        switch ($rule->scope) {
            case PriceRuleScope::All:
                $query->whereRaw('1 = 1');

                return;

            case PriceRuleScope::Categories:
                $query->whereIn('products.category_id', $this->categoryIds($rule));

                return;

            case PriceRuleScope::Products:
                $query->whereIn('products.id', $rule->productIds);

                return;

            case PriceRuleScope::Brands:
                $query->whereIn('products.brand', $this->matchingBrands($rule->brands));

                return;

            case PriceRuleScope::Flags:
                $query->where(function (Builder $q) use ($rule): void {
                    $q->whereRaw('1 = 0');

                    if (in_array(ProductFlag::Featured, $rule->flags, true)) {
                        $q->orWhere('products.is_featured', true);
                    }

                    if (in_array(ProductFlag::New, $rule->flags, true)) {
                        $q->orWhere('products.is_new', true);
                    }
                });

                return;
        }
    }

    /**
     * @return list<int>
     */
    private function categoryIds(PriceRuleData $rule): array
    {
        if (! $rule->includeSubcategories) {
            return $rule->categoryIds;
        }

        $ids = [];

        foreach ($rule->categoryIds as $id) {
            foreach ($this->categories->descendantIds($id) as $descendant) {
                $ids[$descendant] = $descendant;
            }
        }

        return array_values($ids);
    }

    /**
     * Brands are compared case-insensitively. SQL LOWER() does not fold Cyrillic on SQLite, so the (small)
     * list of distinct brands is folded in PHP and the query uses the exact stored spellings.
     *
     * @param  list<string>  $brands  normalized (lowercase) names
     * @return list<string>
     */
    private function matchingBrands(array $brands): array
    {
        if ($brands === []) {
            return [];
        }

        $wanted = array_flip($brands);

        return DB::table('products')
            ->whereNull('deleted_at')
            ->whereNotNull('brand')
            ->distinct()
            ->pluck('brand')
            ->filter(fn (mixed $brand): bool => isset($wanted[PriceRuleData::normalizeBrand((string) $brand)]))
            ->map(fn (mixed $brand): string => (string) $brand)
            ->values()
            ->all();
    }
}
