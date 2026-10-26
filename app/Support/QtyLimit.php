<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Upper bound for a quantity of one position typed by a visitor (cart, reservation, inquiry; D-053). Keeps
 * `qty`, line totals and order totals far from integer / decimal(12,2) overflow.
 */
final class QtyLimit
{
    public const MAX = 999;

    /**
     * Clamps to 1..MAX.
     */
    public static function clamp(int $qty): int
    {
        return max(1, min($qty, self::MAX));
    }
}
