<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Enums\FilterSource;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\DB;

/**
 * Support of the admin editors of filter sets (FL-05, FL-06, FL-17, FL-19): the state of the «Фильтры» tab of a
 * category, the preview «сколько значений / товаров», the example link of the storefront and the bulk inclusion of a
 * characteristic into the sets of many categories. Writes go through {@see CategoryFilterService}.
 */
final class CategoryFilterEditor
{
    public function __construct(
        private readonly CategoryFilterService $filters,
        private readonly CategoryTree $tree,
        private readonly CatalogSettings $settings,
    ) {}

    /**
     * State of the tab: whether the category has an `own` set, the ancestor whose set it inherits (or would inherit
     * after a reset) and the rows to show: the own ones, otherwise the inherited ones.
     *
     * @return array{own: bool, inherited_from: ?int, inherited_name: ?string, rows: list<array{attribute_id: int, is_expanded: bool}>}
     */
    public function state(Category $category): array
    {
        $own = $this->filters->hasOwnSet($category->id);
        $inherited = $this->inheritedFrom($category);

        return [
            'own' => $own,
            'inherited_from' => $inherited,
            'inherited_name' => $inherited !== null ? $this->tree->node($inherited)?->name : null,
            'rows' => $own ? $this->ownRows($category->id) : $this->attributeRows($this->filters->resolve($category->id)->rows->all()),
        ];
    }

    /**
     * The nearest ancestor with an own set: the one that this category inherits from unless it has its own set.
     */
    public function inheritedFrom(Category $category): ?int
    {
        return $category->parent_id === null ? null : $this->filters->resolve((int) $category->parent_id)->sourceCategoryId;
    }

    /**
     * Rows that the inherited set would give (to copy into an own set with «Задать свой набор»).
     *
     * @return list<array{attribute_id: int, is_expanded: bool}>
     */
    public function inheritedRows(Category $category): array
    {
        return $category->parent_id === null ? [] : $this->attributeRows($this->filters->resolve((int) $category->parent_id)->rows->all());
    }

    /**
     * Own rows of the category that point to characteristics, in order (rows of other sources are kept by
     * {@see save()} untouched).
     *
     * @return list<array{attribute_id: int, is_expanded: bool}>
     */
    public function ownRows(int $categoryId): array
    {
        return $this->attributeRows(CategoryFilter::query()
            ->where('category_id', $categoryId)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->all());
    }

    /**
     * Saves the editor: `$own` false removes the own set (inherit again), otherwise the rows replace it. Rows of the
     * `option` source (stage 11) that the category already has are preserved after the characteristics.
     *
     * @param  list<array{attribute_id?: mixed, is_expanded?: mixed}>  $rows
     */
    public function save(Category $category, bool $own, array $rows): void
    {
        if (! $own) {
            $this->filters->clearSet($category);

            return;
        }

        $set = [];

        foreach ($rows as $row) {
            if (filled($row['attribute_id'] ?? null)) {
                $set[] = ['source' => FilterSource::Attribute, 'attribute_id' => (int) $row['attribute_id'], 'is_expanded' => (bool) ($row['is_expanded'] ?? false)];
            }
        }

        foreach (CategoryFilter::query()->where('category_id', $category->id)->where('source', FilterSource::Option->value)->orderBy('sort_order')->get() as $option) {
            $set[] = ['source' => FilterSource::Option, 'option_type_id' => $option->option_type_id, 'is_expanded' => $option->is_expanded];
        }

        $this->filters->replaceSet($category, $set);
    }

    /**
     * Products and distinct values of a characteristic in the category and its subcategories (FL-17 preview).
     *
     * @return array{products: int, values: int}
     */
    public function preview(Category $category, int $attributeId): array
    {
        $attribute = Attribute::query()->find($attributeId);

        if ($attribute === null) {
            return ['products' => 0, 'values' => 0];
        }

        $column = match ($attribute->type) {
            AttributeType::List => 'pav.attribute_value_id',
            AttributeType::Number => 'pav.value_number',
            AttributeType::Boolean => 'pav.value_bool',
        };

        $row = DB::table('product_attribute_values as pav')
            ->join('products as p', 'p.id', '=', 'pav.product_id')
            ->whereNull('p.deleted_at')
            ->whereIn('p.category_id', $this->tree->descendantIds($category->id))
            ->where('pav.attribute_id', $attributeId)
            ->selectRaw('count(distinct pav.product_id) as products, count(distinct '.$column.') as vals')
            ->first();

        return ['products' => (int) ($row->products ?? 0), 'values' => (int) ($row->vals ?? 0)];
    }

    /**
     * GET parameters of the storefront category page that apply an example of the given characteristics (FL-07,
     * FL-19): the first value of a list, the whole range of a number, «да» of a boolean. Characteristics without
     * values in the category are left out.
     *
     * @param  list<int>  $attributeIds
     * @return array<string, mixed>
     */
    public function exampleQuery(Category $category, array $attributeIds): array
    {
        $query = [];
        $categoryIds = $this->tree->descendantIds($category->id);

        $attributes = Attribute::query()->whereIn('id', $attributeIds)->get()->keyBy('id');

        foreach ($attributeIds as $id) {
            $attribute = $attributes->get($id);

            if ($attribute === null) {
                continue;
            }

            $base = DB::table('product_attribute_values as pav')
                ->join('products as p', 'p.id', '=', 'pav.product_id')
                ->whereNull('p.deleted_at')
                ->whereIn('p.category_id', $categoryIds)
                ->where('pav.attribute_id', $id);

            switch ($attribute->type) {
                case AttributeType::List:
                    $valueId = (clone $base)->selectRaw('pav.attribute_value_id as value_id, count(*) as c')->groupBy('pav.attribute_value_id')->orderByDesc('c')->orderBy('pav.attribute_value_id')->value('value_id');
                    $slug = $valueId === null ? null : AttributeValue::query()->whereKey($valueId)->value('slug');

                    if ($slug !== null) {
                        $query['a'][$attribute->slug] = [$slug];
                    }

                    break;
                case AttributeType::Number:
                    $range = (clone $base)->selectRaw('min(pav.value_number) as lo, max(pav.value_number) as hi')->first();

                    if ($range !== null && $range->lo !== null) {
                        $query['a'][$attribute->slug] = [
                            'min' => AttributeValueParser::formatNumber((string) $range->lo),
                            'max' => AttributeValueParser::formatNumber((string) $range->hi),
                        ];
                    }

                    break;
                case AttributeType::Boolean:
                    if ((clone $base)->where('pav.value_bool', true)->exists()) {
                        $query['a'][$attribute->slug] = '1';
                    }

                    break;
            }
        }

        return $query;
    }

    /**
     * «Показать в фильтрах категорий…» (FL-17): adds the characteristic to the own filter sets of the categories.
     * A category without an own set gets a copy of its inherited set plus the characteristic. Categories whose set
     * is full (`filters_max_per_category`) or already has it are skipped.
     *
     * @param  list<int>  $categoryIds
     * @return array{added: int, already: int, full: int}
     */
    public function addAttributeToCategories(Attribute $attribute, array $categoryIds): array
    {
        $result = ['added' => 0, 'already' => 0, 'full' => 0];
        $max = $this->settings->filters_max_per_category;

        foreach (Category::query()->whereIn('id', array_values(array_unique($categoryIds)))->get() as $category) {
            $rows = $this->completeRows($category);

            foreach ($rows as $row) {
                if ($row['source'] === FilterSource::Attribute && (int) ($row['attribute_id'] ?? 0) === $attribute->id) {
                    $result['already']++;

                    continue 2;
                }
            }

            if (count($rows) >= $max) {
                $result['full']++;

                continue;
            }

            $rows[] = ['source' => FilterSource::Attribute, 'attribute_id' => $attribute->id, 'is_expanded' => false];
            $this->filters->replaceSet($category, $rows);
            $result['added']++;
        }

        return $result;
    }

    /**
     * The own rows (all sources) or, without them, the inherited usable rows, as input of replaceSet().
     *
     * @return list<array{source: FilterSource, attribute_id?: int|null, option_type_id?: int|null, is_expanded: bool}>
     */
    private function completeRows(Category $category): array
    {
        $rows = $this->filters->hasOwnSet($category->id)
            ? CategoryFilter::query()->where('category_id', $category->id)->orderBy('sort_order')->orderBy('id')->get()->all()
            : $this->filters->resolve($category->id)->rows->all();

        return array_map(fn (CategoryFilter $row): array => [
            'source' => $row->source,
            'attribute_id' => $row->attribute_id,
            'option_type_id' => $row->option_type_id,
            'is_expanded' => $row->is_expanded,
        ], $rows);
    }

    /**
     * @param  list<CategoryFilter>  $rows
     * @return list<array{attribute_id: int, is_expanded: bool}>
     */
    private function attributeRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            if ($row->source === FilterSource::Attribute && $row->attribute_id !== null) {
                $result[] = ['attribute_id' => $row->attribute_id, 'is_expanded' => $row->is_expanded];
            }
        }

        return $result;
    }
}
