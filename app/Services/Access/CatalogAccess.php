<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\CategoryAccessMode;
use App\Models\CustomerGroup;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;

/**
 * The single place that turns a customer group into an {@see AccessSet} (CG-13, TZ 25.6). The set is computed once per
 * group and catalog version and cached for 10 minutes under `access:{group}:{catalog_version}` (CG-19, 26.3); a
 * change of the group, its categories, any category or any product moves the version on, so a stale set is never read.
 *
 * The category selection is expanded with the cached category tree (no category queries): the subtree of every selected
 * category, and for the «only» mode the ancestors of the selection as navigation categories.
 */
final class CatalogAccess
{
    public const TTL_SECONDS = 600;

    /** @var array<string, AccessSet> Per-instance memo, keyed like the cache (so a bumped version never reads it). */
    private array $memo = [];

    public function __construct(private readonly CategoryTree $tree) {}

    public function for(CustomerGroup $group): AccessSet
    {
        $key = 'access:'.($group->exists ? (string) $group->id : 'code-'.$group->code).':'.CatalogVersion::current();

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $cached = Cache::get($key);

        if (is_array($cached)) {
            try {
                return $this->memo[$key] = AccessSet::fromArray($cached);
            } catch (\Throwable) {
                // A malformed entry (older format): rebuild below.
            }
        }

        $set = $this->compute($group);
        Cache::put($key, $set->toArray(), self::TTL_SECONDS);

        return $this->memo[$key] = $set;
    }

    /**
     * Access of the «Гость» group: the basis of the sitemap, robots.txt and structured data (CG-15).
     */
    public function guest(): AccessSet
    {
        return $this->for(CustomerGroup::guest());
    }

    private function compute(CustomerGroup $group): AccessSet
    {
        $mode = $group->category_access_mode;
        $selectedRoots = $group->exists
            ? $group->categories()->pluck('categories.id')->map(fn (mixed $id): int => (int) $id)->all()
            : [];

        $selected = [];
        $ancestors = [];

        foreach ($selectedRoots as $id) {
            if ($this->tree->node($id) === null) {
                continue;
            }

            foreach ($this->tree->descendantIds($id) as $subtreeId) {
                $selected[$subtreeId] = true;
            }

            foreach ($this->tree->ancestors($id) as $ancestor) {
                $ancestors[$ancestor->id] = true;
            }
        }

        $selectedIds = array_keys($selected);
        $hidden = [];
        $products = [];
        $nav = [];

        if ($mode === CategoryAccessMode::AllExcept) {
            $hidden = $selectedIds;
        } elseif ($mode === CategoryAccessMode::Only) {
            $products = $selectedIds;
            $nav = array_keys($selected + $ancestors);
        }

        return new AccessSet(
            groupId: $group->exists ? (int) $group->id : null,
            groupCode: $group->code,
            sections: array_values(array_map('strval', $group->visible_sections)),
            categoryMode: $mode,
            hiddenCategoryIds: $hidden,
            productCategoryIds: $products,
            navCategoryIds: $nav,
            priceDisplay: $group->price_display,
            allowedSalesModes: $group->allowed_sales_modes !== null ? array_values(array_map('strval', $group->allowed_sales_modes)) : null,
            minOrderAmount: $group->min_order_amount !== null ? Money::fromRubles($group->min_order_amount) : null,
            allowedDeliveryCodes: $group->allowed_delivery_codes !== null ? array_values(array_map('strval', $group->allowed_delivery_codes)) : null,
            allowedPaymentCodes: $group->allowed_payment_codes !== null ? array_values(array_map('strval', $group->allowed_payment_codes)) : null,
            stockDisplay: $group->stock_display,
        );
    }
}
