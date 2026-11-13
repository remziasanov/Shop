<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CustomerGroup;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\PriceRule;
use App\Services\Catalog\CatalogVersion;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Pricing\PriceRuleRepository;
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
        if ($group->is_system) {
            return false;
        }

        // GP-04: a rule without groups is a rule for everyone. A rule that was bound only to this group must not turn into
        // one when the binding disappears with the group (FK cascade): it is switched off instead.
        $orphans = DB::table('price_rule_groups')
            ->where('customer_group_id', $group->id)
            ->whereNotExists(function ($query) use ($group): void {
                $query->selectRaw('1')
                    ->from('price_rule_groups as other')
                    ->whereColumn('other.price_rule_id', 'price_rule_groups.price_rule_id')
                    ->where('other.customer_group_id', '!=', $group->id);
            })
            ->pluck('price_rule_id');

        if ($orphans->isNotEmpty()) {
            PriceRule::query()->whereIn('id', $orphans->all())->update(['is_active' => false]);
            app(PriceRuleRepository::class)->flush();
        }

        return null;
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
        // GP-07: a changed discount (or a new group with one) changes the prices of the group for every product.
        if ($group->wasChanged('default_discount_percent') || ($group->wasRecentlyCreated && bccomp((string) $group->default_discount_percent, '0', 2) > 0)) {
            app(PriceRuleRecalculator::class)->dispatchForGroups([$group->id]);
        }

        $this->bump();
    }

    private function bump(): void
    {
        CatalogVersion::bump();
        DB::afterCommit(static fn () => CatalogVersion::bump());
    }
}
