<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\FilterSource;
use Illuminate\Support\Collection;

/**
 * The filter set the storefront may use for a category: the effective set of {@see CategoryFilterService} reduced to
 * the usable rows. Characteristic rows exist only after the migration from JSON has finished (FL-04, 20.9): until
 * then the product card shows the JSON and there are no characteristic filters. Option rows (VR-19) do not depend on
 * that migration; they are usable while their option is active and a filter.
 */
final class StorefrontFilterSets
{
    public function __construct(
        private readonly CategoryFilterService $sets,
        private readonly AttributeMigrationState $migration,
    ) {}

    public function for(int $categoryId): ResolvedFilterSet
    {
        $set = $this->sets->resolve($categoryId);
        $migrated = $this->migration->isMigrated();

        $rows = $set->rows->filter(fn ($row): bool => match ($row->source) {
            FilterSource::Attribute => $migrated && $row->attribute !== null,
            FilterSource::Option => $row->optionType !== null && $row->optionType->is_active && $row->optionType->is_filterable,
        })->values();

        if ($rows->isEmpty() && ! $migrated) {
            return new ResolvedFilterSet($categoryId, null, new Collection);
        }

        return $rows->count() === $set->rows->count() ? $set : new ResolvedFilterSet($set->categoryId, $set->sourceCategoryId, $rows);
    }
}
