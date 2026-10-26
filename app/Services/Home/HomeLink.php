<?php

declare(strict_types=1);

namespace App\Services\Home;

/**
 * Links entered in the home page builder: a site-relative path or an http(s) / mailto / tel URL only,
 * never `javascript:`, `data:` and the like.
 */
final class HomeLink
{
    public static function isSafe(string $url): bool
    {
        return preg_match('~^(/(?!/)|https?://|mailto:|tel:)~i', trim($url)) === 1;
    }

    public static function clean(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return self::isSafe($url) ? trim($url) : null;
    }
}
