<?php

declare(strict_types=1);

namespace App\Services\Home;

/**
 * The raw «HTML» block is for administrators only (6.13, NF-10). Managers neither see nor can change
 * such blocks: they are taken out of the form data and put back at their position on save.
 */
final class HomeHtmlBlocks
{
    public const TYPE = 'html';

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
