<?php

declare(strict_types=1);

namespace App\Services\Age;

use App\Enums\AgeRestriction;
use App\Services\Catalog\CategoryNode;

/**
 * Admin hint under the age select (AG-12): «Действует: 18+ (из категории “Табак”)».
 */
final class AgeHint
{
    /**
     * @param  array{level: AgeRestriction|null, source: 'product'|'category'|null, category: CategoryNode|null}  $described  result of {@see AgeRestrictionResolver::describeFor()}
     */
    public static function text(array $described, bool $forCategory = false): string
    {
        $level = $described['level'];

        if ($level === null) {
            return __('age_gate.hint.none');
        }

        if ($described['source'] === 'category' && $described['category'] !== null) {
            return __('age_gate.hint.from_category', ['level' => $level->badge(), 'category' => $described['category']->name]);
        }

        return __($forCategory ? 'age_gate.hint.own_category' : 'age_gate.hint.own', ['level' => $level->badge()]);
    }
}
