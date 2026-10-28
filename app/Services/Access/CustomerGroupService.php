<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\CategoryAccessMode;
use App\Exceptions\CustomerGroupException;
use App\Models\CustomerGroup;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Support\Facades\DB;

/**
 * Saves and deletes customer groups together with their category selection (CG-01, CG-05, CG-25). Saving moves the
 * catalog version on, so every cached access set, menu and home page of every group is rebuilt (CG-19).
 */
final class CustomerGroupService
{
    /**
     * @param  array<string, mixed>  $attributes  fillable attributes of the group
     * @param  list<int>|null  $categoryIds  selected categories; null leaves the selection unchanged
     */
    public function save(CustomerGroup $group, array $attributes, ?array $categoryIds = null): CustomerGroup
    {
        DB::transaction(function () use ($group, $attributes, $categoryIds): void {
            $group->fill($attributes)->save();

            if ($categoryIds !== null) {
                // The «all» mode keeps no selection, whatever was left in the form.
                $ids = $group->category_access_mode === CategoryAccessMode::All ? [] : array_values(array_unique($categoryIds));
                $group->categories()->sync($ids);
            }
        });

        // The selection changes after the model events: bump again so no access set of the old selection survives.
        CatalogVersion::bump();

        return $group;
    }

    /**
     * @throws CustomerGroupException for the system groups «Гость» and «Розница»
     */
    public function delete(CustomerGroup $group): void
    {
        if ($group->is_system) {
            throw new CustomerGroupException(__('customer_groups.errors.system_group'));
        }

        DB::transaction(fn () => $group->delete());
        CatalogVersion::bump();
    }
}
