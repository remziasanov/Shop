<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomerGroup;
use App\Models\MenuItem;
use App\Models\Page;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the system groups intact (CG-01), cleans references on deletion (CG-25) and bumps the catalog version so
 * every cache that depends on the group is rebuilt (CG-19).
 */
final class CustomerGroupObserver
{
    public function updating(CustomerGroup $group): void
    {
        if ($group->getOriginal('is_system')) {
            // The code and the system flag of «Гость» and «Розница» are not editable.
            $group->code = (string) $group->getOriginal('code');
            $group->is_system = true;
            $group->is_active = true;
        }
    }

    /**
     * System groups cannot be deleted; returning false cancels the delete.
     */
    public function deleting(CustomerGroup $group): ?bool
    {
        return $group->is_system ? false : null;
    }

    public function deleted(CustomerGroup $group): void
    {
        // The id leaves visible_group_ids of pages and menu items. A list that becomes empty stays empty: the item is
        // then hidden from everyone instead of silently becoming public.
        foreach ([Page::class, MenuItem::class] as $model) {
            $model::query()->whereNotNull('visible_group_ids')->get()->each(function (Page|MenuItem $item) use ($group): void {
                $ids = $item->visible_group_ids ?? [];

                if (in_array($group->id, $ids, true)) {
                    $item->visible_group_ids = array_values(array_diff($ids, [$group->id])) ?: [];
                    $item->saveQuietly();
                }
            });
        }

        $this->bump();
    }

    public function saved(CustomerGroup $group): void
    {
        $this->bump();
    }

    private function bump(): void
    {
        CatalogVersion::bump();
        DB::afterCommit(static fn () => CatalogVersion::bump());
    }
}
