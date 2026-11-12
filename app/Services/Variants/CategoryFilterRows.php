<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Enums\FilterSource;
use App\Exceptions\AttributeException;
use App\Exceptions\OptionException;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\OptionType;
use App\Services\Catalog\CategoryFilterService;

/**
 * The filter set of the «Фильтры» tab with both sources (FL-05, VR-19): characteristics and options with the flag
 * «использовать как фильтр». The state of the tab is a list of rows `{source, attribute_id|option_type_id, is_expanded}`
 * in display order; saving replaces the own set through {@see CategoryFilterService}.
 */
final class CategoryFilterRows
{
    public function __construct(
        private readonly CategoryFilterService $filters,
    ) {}

    /**
     * Tab state: whether the category has an own set and the rows to show (own, otherwise the inherited ones).
     *
     * @return array{own: bool, rows: list<array{source: string, attribute_id: int|null, option_type_id: int|null, is_expanded: bool}>}
     */
    public function state(Category $category): array
    {
        $own = $this->filters->hasOwnSet($category->id);

        return [
            'own' => $own,
            'rows' => $own ? $this->ownRows($category->id) : $this->resolvedRows($category->id),
        ];
    }

    /**
     * Rows of the set the category would inherit (a copy for «Задать свой набор»).
     *
     * @return list<array{source: string, attribute_id: int|null, option_type_id: int|null, is_expanded: bool}>
     */
    public function inheritedRows(Category $category): array
    {
        return $category->parent_id === null ? [] : $this->resolvedRows((int) $category->parent_id);
    }

    /**
     * Saves the editor: `$own` false removes the own set (inherit again), otherwise the rows replace it.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws AttributeException the set is too long, repeats a source or names an unknown characteristic
     * @throws OptionException an option that is unknown or not usable as a filter
     */
    public function save(Category $category, bool $own, array $rows): void
    {
        if (! $own) {
            $this->filters->clearSet($category);

            return;
        }

        $set = [];

        foreach ($rows as $row) {
            $source = FilterSource::tryFrom((string) ($row['source'] ?? '')) ?? FilterSource::Attribute;

            if ($source === FilterSource::Option) {
                if (! filled($row['option_type_id'] ?? null)) {
                    continue;
                }

                $id = (int) $row['option_type_id'];
                $type = OptionType::query()->find($id);

                if ($type === null) {
                    throw OptionException::notFilterable('#'.$id);
                }

                if (! $type->is_filterable && ! $this->alreadyUsed($category, $id)) {
                    throw OptionException::notFilterable($type->name);
                }

                $set[] = ['source' => FilterSource::Option, 'option_type_id' => $id, 'is_expanded' => (bool) ($row['is_expanded'] ?? false)];

                continue;
            }

            if (filled($row['attribute_id'] ?? null)) {
                $set[] = ['source' => FilterSource::Attribute, 'attribute_id' => (int) $row['attribute_id'], 'is_expanded' => (bool) ($row['is_expanded'] ?? false)];
            }
        }

        $this->filters->replaceSet($category, $set);
    }

    /**
     * Option types that can be chosen in a row: active ones with the filter flag.
     *
     * @return array<int, string>
     */
    public function optionChoices(): array
    {
        return OptionType::query()->where('is_active', true)->where('is_filterable', true)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all();
    }

    public function optionName(mixed $id): ?string
    {
        return filled($id) ? OptionType::query()->whereKey((int) $id)->value('name') : null;
    }

    /**
     * An option that was filterable when it entered the set and lost the flag later stays in the set until the
     * manager removes it (saving the tab must not fail because of it).
     */
    private function alreadyUsed(Category $category, int $optionTypeId): bool
    {
        return CategoryFilter::query()->where('category_id', $category->id)->where('source', FilterSource::Option->value)->where('option_type_id', $optionTypeId)->exists();
    }

    /**
     * @return list<array{source: string, attribute_id: int|null, option_type_id: int|null, is_expanded: bool}>
     */
    private function ownRows(int $categoryId): array
    {
        return $this->map(CategoryFilter::query()->where('category_id', $categoryId)->orderBy('sort_order')->orderBy('id')->get()->all());
    }

    /**
     * @return list<array{source: string, attribute_id: int|null, option_type_id: int|null, is_expanded: bool}>
     */
    private function resolvedRows(int $categoryId): array
    {
        return $this->map($this->filters->resolve($categoryId)->rows->all());
    }

    /**
     * @param  list<CategoryFilter>  $rows
     * @return list<array{source: string, attribute_id: int|null, option_type_id: int|null, is_expanded: bool}>
     */
    private function map(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'source' => $row->source->value,
                'attribute_id' => $row->attribute_id,
                'option_type_id' => $row->option_type_id,
                'is_expanded' => $row->is_expanded,
            ];
        }

        return $result;
    }
}
