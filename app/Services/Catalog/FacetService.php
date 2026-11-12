<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Enums\FilterSource;
use App\Models\AttributeValue;
use App\Models\OptionValue;
use App\Services\Access\ViewerContext;
use App\Settings\CatalogSettings;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
 * - Option rows (VR-19) are counted the same way, one query per option within the same budget: the cards of the
 *   selection without any option filter, joined to their active variants that satisfy the OTHER selected options (and
 *   the availability switch), distinct cards per value.
 * - The result is cached in the file cache by group, category, normalized parameters and catalog version (FL-11).
 *   No window functions or CTEs (MySQL 5.7).
 */
final class FacetService
{
    /**
     * Optimizer hint right after `select` of the numeric query: the materialized selection drives the join and the
     * values are probed by product (FL-12). Without it MySQL 8 prefers a range scan of the number index (60 000 rows
     * at 20 000 products x 3 numbers) and probes the selection from there: ~200 ms instead of ~15 ms. Not used for the
     * list queries, where the same hint is much slower (their selection is larger). It is a plain comment for SQLite
     * and MySQL 5.7, which ignore it.
     */
    private const SELECTION_FIRST = '/*+ JOIN_ORDER(sel, fv) NO_BNL(fv) NO_RANGE_OPTIMIZATION(fv) */ ';

    /** FL-10: 9 list filters + 1 numeric query. */
    public const MAX_QUERIES = 10;

    public function __construct(
        private readonly StorefrontFilterSets $sets,
        private readonly CatalogQuery $catalog,
        private readonly ViewerContext $viewer,
        private readonly CatalogSettings $settings,
        private readonly OptionFilterApplier $optionFilters,
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

        /** @var array<int, array<int, int>> $optionCounts option type id => value id => cards */
        $optionCounts = [];

        foreach ($set->rows as $row) {
            $attribute = $row->attribute;

            if ($attribute !== null && $attribute->type === AttributeType::List && $budget > 0) {
                $budget--;
                $counts[$attribute->id] = $this->counts($attribute->id, $attribute->slug, $filters, $categoryIds);
            } elseif ($row->source === FilterSource::Option && $row->optionType !== null && $budget > 0) {
                $budget--;
                $optionCounts[$row->optionType->id] = $this->optionCounts($row->optionType->id, $row->optionType->slug, $filters, $categoryIds);
            }
        }

        $labels = $this->dictionary($counts);
        $optionLabels = $this->optionDictionary($optionCounts, $filters);
        $groups = [];

        foreach ($set->rows as $row) {
            $type = $row->source === FilterSource::Option ? $row->optionType : null;

            if ($type !== null) {
                if (isset($optionCounts[$type->id])) {
                    $group = new FacetGroup(
                        slug: $type->slug,
                        name: $type->name,
                        type: AttributeType::List,
                        unit: null,
                        expanded: $row->is_expanded,
                        values: $this->optionValues($optionCounts[$type->id], $optionLabels, $filters->options->get($type->slug)),
                        source: FacetGroup::SOURCE_OPTION,
                        display: $type->display_type->value,
                    );

                    if (! $group->isEmpty()) {
                        $groups[] = $group;
                    }
                }

                continue;
            }

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
        return $this->fromSelection($filters, $categoryIds, [$slug])
            ->where('fv.attribute_id', $attributeId)
            ->groupBy('fv.attribute_value_id')
            ->get(['fv.attribute_value_id as value_id', DB::raw('count(*) as product_count')])
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
     * facet(O): cards per value of an option, the filter of O itself left out (VR-19). Distinct cards, so a card with
     * several variants of one colour counts once.
     *
     * @param  list<int>  $categoryIds
     * @return array<int, int> value id => number of cards
     */
    private function optionCounts(int $typeId, string $slug, CatalogFilters $filters, array $categoryIds): array
    {
        $ids = $this->catalog->selection($filters, $categoryIds, null, [], true)
            ->reorder()
            ->select('products.id')
            ->distinct()
            ->toBase();

        $query = DB::query()
            ->fromSub($ids, 'sel')
            ->crossJoin('product_variants as pv', 'pv.product_id', '=', 'sel.id')
            ->join('product_variant_values as fv', 'fv.variant_id', '=', 'pv.id')
            ->where('pv.is_active', true)
            ->whereNull('pv.deleted_at')
            ->where('fv.option_type_id', $typeId);

        $this->optionFilters->constrain($query, $filters->options, [$slug]);

        return $query
            ->groupBy('fv.option_value_id')
            ->get(['fv.option_value_id as value_id', DB::raw('count(distinct sel.id) as product_count')])
            ->mapWithKeys(fn (object $row): array => [(int) $row->value_id => (int) $row->product_count])
            ->all();
    }

    /**
     * Slug, label, swatch and order of the counted option values and of the selected ones (a dictionary read).
     *
     * @param  array<int, array<int, int>>  $counts
     * @return array<int, OptionValue>
     */
    private function optionDictionary(array $counts, CatalogFilters $filters): array
    {
        $ids = [];

        foreach ($counts as $perValue) {
            array_push($ids, ...array_keys($perValue));
        }

        foreach ($filters->options->filters as $filter) {
            array_push($ids, ...$filter->valueIds());
        }

        if ($ids === []) {
            return [];
        }

        return OptionValue::query()->whereIn('id', array_unique($ids))->get(['id', 'slug', 'value', 'hex', 'image_path', 'sort_order'])->keyBy('id')->all();
    }

    /**
     * Values of an option block: counted and selected values, selected ones even at zero (FL-09), in dictionary order.
     *
     * @param  array<int, int>  $counted
     * @param  array<int, OptionValue>  $labels
     * @return list<FacetValue>
     */
    private function optionValues(array $counted, array $labels, ?OptionFilter $selected): array
    {
        $selectedIds = array_flip($selected !== null ? $selected->valueIds() : []);
        $items = [];

        foreach ($counted + array_fill_keys(array_keys($selectedIds), 0) as $valueId => $count) {
            $value = $labels[$valueId] ?? null;

            if ($value === null || ($count < 1 && ! isset($selectedIds[$valueId]))) {
                continue;
            }

            $image = $value->image_path !== null && $value->image_path !== '' ? Storage::disk('public')->url($value->image_path) : null;
            $items[$valueId] = ['sort' => $value->sort_order, 'value' => new FacetValue($value->slug, $value->value, $counted[$valueId] ?? 0, isset($selectedIds[$valueId]), $value->hex, $image)];
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
        $rows = $this->fromSelection($filters, $categoryIds, $slugs)
            ->whereIn('fv.attribute_id', $attributeIds)
            ->whereNotNull('fv.value_number')
            ->groupBy('fv.attribute_id')
            ->get([DB::raw(self::SELECTION_FIRST.'fv.attribute_id'), DB::raw('min(fv.value_number) as range_min'), DB::raw('max(fv.value_number) as range_max')]);

        $ranges = [];

        foreach ($rows as $row) {
            $ranges[(int) $row->attribute_id] = [
                'min' => AttributeValueParser::formatNumber((string) $row->range_min),
                'max' => AttributeValueParser::formatNumber((string) $row->range_max),
            ];
        }

        return $ranges;
    }

    /**
     * Values of the characteristics (`fv`) of the products of the selection. The selection is a derived table of
     * product ids (DISTINCT keeps MySQL from merging it into the outer query), so the selective filters drive the
     * query and the values are fetched through the (product_id, attribute_id) index, instead of range-scanning the
     * value indexes of the whole characteristic (FL-12). `(product_id, attribute_id, value)` is unique, so a product
     * counts once per value.
     *
     * @param  list<int>  $categoryIds
     * @param  list<string>  $exceptAttributes  slugs of characteristics whose filter is left out
     */
    private function fromSelection(CatalogFilters $filters, array $categoryIds, array $exceptAttributes): QueryBuilder
    {
        $ids = $this->catalog->selection($filters, $categoryIds, null, $exceptAttributes)
            ->reorder()
            ->select('products.id')
            ->distinct()
            ->toBase();

        return DB::query()
            ->fromSub($ids, 'sel')
            // CROSS JOIN pins the join order in SQLite (the selection is the outer table); MySQL treats it as INNER JOIN.
            ->crossJoin('product_attribute_values as fv', 'fv.product_id', '=', 'sel.id');
    }
}
