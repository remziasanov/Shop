<?php

declare(strict_types=1);

namespace App\Services\Promo;

/**
 * Promo code text rules (PM-01): stored and compared in upper case without surrounding spaces; only Latin letters,
 * digits and hyphens, 4 to 40 characters.
 */
final class PromoCodeNormalizer
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 40;

    /**
     * What the customer typed, ready for lookup: trimmed and upper-cased (also Cyrillic input stays recognizable as invalid).
     */
    public static function normalize(string $input): string
    {
        return mb_strtoupper(trim($input));
    }

    /**
     * Whether the (normalized or raw) text is an acceptable code.
     */
    public static function isValid(string $input): bool
    {
        return preg_match('/^[A-Z0-9-]{'.self::MIN_LENGTH.','.self::MAX_LENGTH.'}$/', self::normalize($input)) === 1;
    }
}
