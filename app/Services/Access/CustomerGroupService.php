<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\CategoryAccessMode;
use App\Exceptions\CustomerGroupException;
use App\Models\Customer;
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
     * Makes the group the one new customers get (CG-11): the flag moves, there is exactly one such group.
     *
     * @throws CustomerGroupException the group is inactive
     */
    public function setDefaultForNew(CustomerGroup $group): void
    {
        if (! $group->is_active) {
            throw new CustomerGroupException(__('customer_groups.errors.inactive_default'));
        }

        DB::transaction(function () use ($group): void {
            CustomerGroup::query()->where('is_default_for_new', true)->where('id', '!=', $group->id)->update(['is_default_for_new' => false]);
            CustomerGroup::query()->whereKey($group->id)->update(['is_default_for_new' => true]);
        });

        $group->refresh();
        CatalogVersion::bump();
    }

    /**
     * Deletes a group (CG-25). Its customers move to `$moveCustomersTo` («Розница» by default); the group that new
     * customers get cannot be deleted before another one is chosen.
     *
     * @throws CustomerGroupException for the system groups «Гость» and «Розница» and for the default group of new customers
     */
    public function delete(CustomerGroup $group, ?CustomerGroup $moveCustomersTo = null): void
    {
        if ($group->is_system) {
            throw new CustomerGroupException(__('customer_groups.errors.system_group'));
        }

        if ($group->is_default_for_new) {
            throw new CustomerGroupException(__('customer_groups.errors.default_group'));
        }

        $target = $moveCustomersTo ?? CustomerGroup::retail();

        if ($target->id === $group->id || $target->isGuest() || ! $target->is_active) {
            throw new CustomerGroupException(__('customer_groups.errors.target_invalid'));
        }

        DB::transaction(function () use ($group, $target): void {
            Customer::query()->where('customer_group_id', $group->id)->update(['customer_group_id' => $target->id]);
            $group->delete();
        });

        CatalogVersion::bump();
    }
}
