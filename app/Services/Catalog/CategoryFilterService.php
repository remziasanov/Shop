<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\FilterSource;
use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\DB;

/**
 * Filter sets of categories (FL-05, FL-06): the effective set with inheritance and the writer of own sets.
 *
 * Inheritance: a category without own rows takes the set of the nearest ancestor that has rows; sets are never
 * merged. The ancestors come from the cached category tree, the rows of the whole chain are read in one query.
 * The built-in filters of SF-13 are not part of the set.
 */
final class CategoryFilterService
{
    /** @var array<int, ResolvedFilterSet> */
    private array $memo = [];

    public function __construct(
        private readonly CategoryTree $tree,
        private readonly CatalogSettings $settings,
    ) {}

    /**
     * Effective filters of the category. Rows of inactive or non-filterable characteristics are skipped, then the
     * first `filters_max_per_category` remain.
     */
    public function resolve(int $categoryId): ResolvedFilterSet
    {
        if (isset($this->memo[$categoryId])) {
            return $this->memo[$categoryId];
        }

        $chain = $this->tree->selfAndAncestorIds($categoryId);

        $byCategory = CategoryFilter::query()
            ->whereIn('category_id', $chain)
            ->with(['attribute', 'optionType'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('category_id');

        $source = null;

        foreach ($chain as $id) {
            if ($byCategory->has($id)) {
                $source = $id;

                break;
            }
        }

        $rows = $source === null ? collect() : $byCategory[$source]
            ->filter(fn (CategoryFilter $row): bool => $row->source !== FilterSource::Attribute
                || ($row->attribute !== null && $row->attribute->is_active && $row->attribute->is_filterable))
            ->take(max(0, $this->settings->filters_max_per_category))
            ->values();

        return $this->memo[$categoryId] = new ResolvedFilterSet($categoryId, $source, $rows);
    }

    /**
     * True when the category has rows of its own (the editor shows «Задать свой набор» otherwise).
     */
    public function hasOwnSet(int $categoryId): bool
    {
        return CategoryFilter::query()->where('category_id', $categoryId)->exists();
    }

    /**
     * Replaces the own set of the category. Rows: `['attribute_id' => int, 'is_expanded' => bool]` (source
     * attribute) or `['source' => 'option', 'option_type_id' => int, 'is_expanded' => bool]`; the order of the list is the
     * display order. An empty list removes the own set, the category inherits again.
     *
     * @param  list<array{source?: string|FilterSource, attribute_id?: int|null, option_type_id?: int|null, is_expanded?: bool}>  $rows
     *
     * @throws AttributeException when the set is too long, has the same source twice or an unknown attribute
     */
    public function replaceSet(Category $category, array $rows): void
    {
        $max = $this->settings->filters_max_per_category;

        if (count($rows) > $max) {
            throw AttributeException::filtersLimit($max);
        }

        $insert = [];
        $seen = [];

        foreach ($rows as $position => $row) {
            $raw = $row['source'] ?? FilterSource::Attribute;
            $source = $raw instanceof FilterSource ? $raw : (FilterSource::tryFrom((string) $raw) ?? FilterSource::Attribute);
            $attributeId = $source === FilterSource::Attribute ? (int) ($row['attribute_id'] ?? 0) : null;
            $optionTypeId = $source === FilterSource::Option ? (int) ($row['option_type_id'] ?? 0) : null;
            $key = $source->value.':'.($attributeId ?? $optionTypeId);

            if (isset($seen[$key])) {
                throw AttributeException::duplicateFilter();
            }

            $seen[$key] = true;

            if ($attributeId !== null && ! Attribute::query()->whereKey($attributeId)->exists()) {
                throw AttributeException::unknownAttribute('#'.$attributeId);
            }

            $insert[] = [
                'category_id' => $category->id,
                'source' => $source->value,
                'attribute_id' => $attributeId,
                'option_type_id' => $optionTypeId,
                'sort_order' => ($position + 1) * 10,
                'is_expanded' => (bool) ($row['is_expanded'] ?? false),
            ];
        }

        DB::transaction(function () use ($category, $insert): void {
            CategoryFilter::query()->where('category_id', $category->id)->delete();

            if ($insert !== []) {
                CategoryFilter::query()->insert($insert);
            }
        });

        $this->memo = [];
        CatalogVersion::bump();
        DB::afterCommit(static fn () => CatalogVersion::bump());
    }

    /**
     * «Использовать набор родителя»: removes the own rows.
     */
    public function clearSet(Category $category): void
    {
        $this->replaceSet($category, []);
    }
}
