<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * `visible_group_ids` (CG-10): ids of the customer groups an item is shown to; null = every group. An empty list hides
 * the item from everyone (what is left when the only chosen group is deleted).
 *
 * @property list<int>|null $visible_group_ids
 */
trait HasGroupVisibility
{
    public function isVisibleToGroup(?int $groupId): bool
    {
        $ids = $this->visible_group_ids;

        if ($ids === null) {
            return true;
        }

        return $groupId !== null && in_array($groupId, $ids, true);
    }

    /**
     * Normalizes the value of a multi-select: integer ids, no duplicates; nothing selected = every group (null).
     *
     * @return list<int>|null
     */
    public static function normalizeGroupIds(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($value, fn (mixed $id): bool => is_numeric($id)))));

        return $ids === [] ? null : $ids;
    }
}
