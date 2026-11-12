<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\FilterSource;
use Illuminate\Support\Collection;

/**
 * The filter set the storefront may use for a category: the effective set of {@see CategoryFilterService} reduced to
 * characteristic rows. It is empty until the migration from JSON has finished (FL-04, 20.9): until then the product
 * card shows the JSON and there are no filters. Option rows (VR-19) join in stage 11.
 */
final class StorefrontFilterSets
{
    public function __construct(
        private readonly CategoryFilterService $sets,
        private readonly AttributeMigrationState $migration,
    ) {}

    public function for(int $categoryId): ResolvedFilterSet
    {
        if (! $this->migration->isMigrated()) {
            return new ResolvedFilterSet($categoryId, null, new Collection);
        }

        $set = $this->sets->resolve($categoryId);
        $rows = $set->rows->filter(fn ($row): bool => $row->source === FilterSource::Attribute && $row->attribute !== null)->values();

        return $rows->count() === $set->rows->count() ? $set : new ResolvedFilterSet($set->categoryId, $set->sourceCategoryId, $rows);
    }
}
