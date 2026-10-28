<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Models\User;
use App\Services\Users\PermissionService;

/**
 * The raw «HTML» block needs the `html_block.edit` right (6.13, NF-10, RB-11). Users without it neither see nor
 * can change such blocks: they are taken out of the form data and put back at their position on save.
 */
final class HomeHtmlBlocks
{
    public const TYPE = 'html';

    public function __construct(private readonly PermissionService $permissions) {}

    public function canEdit(User $user): bool
    {
        return $this->permissions->allows($user, 'html_block.edit');
    }

    /**
     * Blocks the user may see in the form.
     *
     * @param  array<int|string, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public function visibleTo(User $user, array $blocks): array
    {
        return $this->canEdit($user) ? array_values(array_filter($blocks, 'is_array')) : $this->without($blocks);
    }

    /**
     * Blocks to store: the submitted ones for a user with the right, otherwise html blocks come only from storage.
     *
     * @param  array<int|string, mixed>  $submitted
     * @param  array<int|string, mixed>  $stored
     * @return list<array<string, mixed>>
     */
    public function prepareForSave(User $user, array $submitted, array $stored): array
    {
        return $this->canEdit($user) ? array_values(array_filter($submitted, 'is_array')) : $this->restore($submitted, $stored);
    }

    /**
     * @param  array<int|string, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public function without(array $blocks): array
    {
        return array_values(array_filter($blocks, fn ($block): bool => is_array($block) && ($block['type'] ?? null) !== self::TYPE));
    }

    /**
     * Drop any html block the (non-admin) user submitted and re-insert the stored ones at their old index.
     *
     * @param  array<int|string, mixed>  $submitted
     * @param  array<int|string, mixed>  $stored  blocks currently saved in the settings
     * @return list<array<string, mixed>>
     */
    public function restore(array $submitted, array $stored): array
    {
        $blocks = $this->without($submitted);

        foreach (array_values($stored) as $index => $block) {
            if (is_array($block) && ($block['type'] ?? null) === self::TYPE) {
                array_splice($blocks, min($index, count($blocks)), 0, [$block]);
            }
        }

        return $blocks;
    }
}
