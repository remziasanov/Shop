<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\FilterSource;
use App\Models\CategoryFilter;
use Illuminate\Support\Collection;

/**
 * The effective set of configurable filters of a category (FL-05, FL-06).
 */
final class ResolvedFilterSet
{
    /**
     * @param  int  $categoryId  the category that was asked for
     * @param  int|null  $sourceCategoryId  the category that owns the rows (itself or the nearest ancestor), null when nobody does
     * @param  Collection<int, CategoryFilter>  $rows  usable rows in order, at most `filters_max_per_category`, `attribute` / `optionType` loaded
     */
    public function __construct(
        public readonly int $categoryId,
        public readonly ?int $sourceCategoryId,
        public readonly Collection $rows,
    ) {}

    /**
     * The set belongs to an ancestor ("Набор унаследован от «Одежда»").
     */
    public function isInherited(): bool
    {
        return $this->sourceCategoryId !== null && $this->sourceCategoryId !== $this->categoryId;
    }

    public function isEmpty(): bool
    {
        return $this->rows->isEmpty();
    }

    /**
     * The set has a variant option row (VR-19): the «только доступные модификации» switch belongs to it.
     */
    public function hasOptions(): bool
    {
        return $this->rows->contains(fn (CategoryFilter $row): bool => $row->source === FilterSource::Option && $row->optionType !== null);
    }
}
