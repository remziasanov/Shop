<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Models\AttributeValue;
use App\Services\Access\ViewerContext;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Facets of the category filters (FL-09…FL-11, FL-21, TZ 20.5): the counters of list values and the range hints of
 * numbers.
 *
 * - One grouped query per list filter (the labels of the counted values are read from the dictionary afterwards, a
 *   primary-key lookup that is not a counting query), counted with all other filters applied and without the filter of the
 *   characteristic itself, so one more value can be added (FL-09). One query for the minimum and maximum of all number
 *   filters. At most {@see MAX_QUERIES} queries in total (FL-10); list filters beyond the budget get no block.
 * - The base is {@see CatalogQuery::selection()}: active products of the visible categories of the viewer's group, the
 *   same query as the listing, so hidden products never reach a counter (FL-21).
 * - Only values with a counter above zero are listed, selected values always (FL-09).
 * - The result is cached in the file cache by group, category, normalized parameters and catalog version (FL-11).
 *   No window functions or CTEs (MySQL 5.7).
 */
final class FacetService
{
    /** FL-10: 9 list filters + 1 numeric query. */
    public const MAX_QUERIES = 10;

    public function __construct(
        private readonly StorefrontFilterSets $sets,
        private readonly CatalogQuery $catalog,
        private readonly ViewerContext $viewer,
        private readonly CatalogSettings $settings,
    ) {}

    /**
     * FL-11 cache key. The normalized parameters (built-in filters and the characteristic selection, no sort, no page)
     * are hashed.
     */
    public function cacheKey(int $categoryId, CatalogFilters $filters): string
    {
        $query = $filters->restrictedTo($this->viewer->access())->toQuery(false);

        return 'facets:'.$this->viewer->group()->id.':'.$categoryId.':'.sha1((string) json_encode($query)).':'.CatalogVersion::current();
    }

    /**
     * Filter blocks of the category in set order; empty when the category has no set (or the migration is pending).
     *
     * @param  list<int>  $categoryIds  the category and its descendants
     * @param  ResolvedFilterSet|null  $set  the storefront set of the category when the caller already has it (saves queries)
     * @return list<FacetGroup>
     */
    public function forCategory(int $categoryId, array $categoryIds, CatalogFilters $filters, ?ResolvedFilterSet $set = null): array
    {
        $set ??= $this->sets->for($categoryId);

        if ($set->isEmpty()) {
            return [];
        }

        $minutes = $this->settings->facets_cache_minutes;

        if ($minutes <= 0) {
            return $this->build($set, $categoryIds, $filters);
        }

        /** @var list<array<string, mixed>> $data */
        $data = Cache::remember(
            $this->cacheKey($categoryId, $filters),
            now()->addMinutes($minutes),
            fn (): array => array_map(fn (FacetGroup $group): array => $group->toArray(), $this->build($set, $categoryIds, $filters)),
        );

        return array_map(FacetGroup::fromArray(...), $data);
    }

    /**
     * @param  list<int>  $categoryIds
     * @return list<FacetGroup>
     */
    private function build(ResolvedFilterSet $set, array $categoryIds, CatalogFilters $filters): array
    {
        $selection = $filters->attributes;

        $numberIds = [];
        $numberSlugs = [];

        foreach ($set->rows as $row) {
            if ($row->attribute !== null && $row->attribute->type === AttributeType::Number) {
                $numberIds[] = $row->attribute->id;
                $numberSlugs[] = $row->attribute->slug;
            }
        }

        $budget = self::MAX_QUERIES - ($numberIds !== [] ? 1 : 0);
        $ranges = $numberIds !== [] ? $this->ranges($filters, $categoryIds, $numberIds, $numberSlugs) : [];

        // Phase 1: one counting query per list filter within the budget.
        /** @var array<int, array<int, int>> $counts attribute id => value id => products */
        $counts = [];

        foreach ($set->rows as $row) {
            $attribute = $row->attribute;

            if ($attribute !== null && $attribute->type === AttributeType::List && $budget > 0) {
                $budget--;
                $counts[$attribute->id] = $this->counts($attribute->id, $attribute->slug, $filters, $categoryIds);
            }
        }

        $labels = $this->dictionary($counts);
        $groups = [];

        foreach ($set->rows as $row) {
            $attribute = $row->attribute;

            if ($attribute === null) {
                continue;
            }

            $selected = $selection->get($attribute->slug);

            if ($attribute->type === AttributeType::List) {
                if (! isset($counts[$attribute->id])) {
                    continue;
                }

                $group = new FacetGroup(
                    slug: $attribute->slug,
                    name: $attribute->name,
                    type: $attribute->type,
                    unit: $attribute->unit,
                    expanded: $row->is_expanded,
                    values: $this->listValues($counts[$attribute->id], $labels, $selected),
                );
            } elseif ($attribute->type === AttributeType::Number) {
                $group = new FacetGroup(
                    slug: $attribute->slug,
                    name: $attribute->name,
                    type: $attribute->type,
                    unit: $attribute->unit,
                    expanded: $row->is_expanded,
                    min: $ranges[$attribute->id]['min'] ?? null,
                    max: $ranges[$attribute->id]['max'] ?? null,
                    selectedMin: $selected?->min,
                    selectedMax: $selected?->max,
                );
            } else {
                $group = new FacetGroup(
                    slug: $attribute->slug,
                    name: $attribute->name,
                    type: $attribute->type,
                    unit: null,
                    expanded: $row->is_expanded,
                    checked: $selected !== null && $selected->enabled,
                );
            }

            // A characteristic without values in the selection and without a selection is not worth a block.
            if (! $group->isEmpty()) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * facet(A): products per value of a list, the filter of A itself left out. No join with the dictionary: the counters
     * come straight from the covering index `pav_attribute_value_product_index`.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, int> value id => number of products
     */
    private function counts(int $attributeId, string $slug, CatalogFilters $filters, array $categoryIds): array
    {
        return $this->catalog->selection($filters, $categoryIds, null, [$slug])
            ->join('product_attribute_values as fv', 'fv.product_id', '=', 'products.id')
            ->where('fv.attribute_id', $attributeId)
            ->reorder()
            ->groupBy('fv.attribute_value_id')
            ->toBase()
            ->get(['fv.attribute_value_id as value_id', DB::raw('count(distinct fv.product_id) as product_count')])
            ->mapWithKeys(fn (object $row): array => [(int) $row->value_id => (int) $row->product_count])
            ->all();
    }

    /**
     * Slug, label and order of all counted values in one primary-key lookup (a dictionary read, not a counting query).
     *
     * @param  array<int, array<int, int>>  $counts
     * @return array<int, AttributeValue>
     */
    private function dictionary(array $counts): array
    {
        $ids = [];

        foreach ($counts as $perValue) {
            array_push($ids, ...array_keys($perValue));
        }

        if ($ids === []) {
            return [];
        }

        return AttributeValue::query()->whereIn('id', $ids)->get(['id', 'slug', 'value', 'sort_order'])->keyBy('id')->all();
    }

    /**
     * Values of a list block: counted values and the selected ones, selected values even at zero (FL-09), in
     * dictionary order.
     *
     * @param  array<int, int>  $counted
     * @param  array<int, AttributeValue>  $labels
     * @return list<FacetValue>
     */
    private function listValues(array $counted, array $labels, ?AttributeFilter $selected): array
    {
        $selectedSlugs = [];
        $items = [];

        foreach ($selected !== null ? $selected->values : [] as $value) {
            $selectedSlugs[$value['slug']] = $value;
        }

        foreach ($counted as $valueId => $count) {
            $value = $labels[$valueId] ?? null;

            if ($value === null || ($count < 1 && ! isset($selectedSlugs[$value->slug]))) {
                continue;
            }

            $items[$valueId] = ['sort' => $value->sort_order, 'value' => new FacetValue($value->slug, $value->value, $count, isset($selectedSlugs[$value->slug]))];
        }

        // Selected values stay visible even when the other filters leave no product with them.
        foreach ($selectedSlugs as $value) {
            $items[$value['id']] ??= ['sort' => $value['sort'], 'value' => new FacetValue($value['slug'], $value['label'], 0, true)];
        }

        uksort($items, fn (int $a, int $b): int => [$items[$a]['sort'], $a] <=> [$items[$b]['sort'], $b]);

        return array_values(array_map(fn (array $item): FacetValue => $item['value'], $items));
    }

    /**
     * facet_numeric: minimum and maximum of every number filter in one query. The number filters themselves are left
     * out, so the hint stays the full range of the selection instead of shrinking to the chosen one.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $attributeIds
     * @param  list<string>  $slugs
     * @return array<int, array{min: string, max: string}>
     */
    private function ranges(CatalogFilters $filters, array $categoryIds, array $attributeIds, array $slugs): array
    {
        $rows = $this->catalog->selection($filters, $categoryIds, null, $slugs)
            ->join('product_attribute_values as fv', 'fv.product_id', '=', 'products.id')
            ->whereIn('fv.attribute_id', $attributeIds)
            ->whereNotNull('fv.value_number')
            ->reorder()
            ->groupBy('fv.attribute_id')
            ->toBase()
            ->get(['fv.attribute_id', DB::raw('min(fv.value_number) as range_min'), DB::raw('max(fv.value_number) as range_max')]);

        $ranges = [];

        foreach ($rows as $row) {
            $ranges[(int) $row->attribute_id] = [
                'min' => AttributeValueParser::formatNumber((string) $row->range_min),
                'max' => AttributeValueParser::formatNumber((string) $row->range_max),
            ];
        }

        return $ranges;
    }
}
