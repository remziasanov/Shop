<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Models\MenuItem;

/**
 * Links entered in the home page builder: a site-relative path or an http(s) / mailto / tel URL only,
 * never `javascript:`, `data:` and the like.
 */
final class HomeLink
{
    public static function isSafe(string $url): bool
    {
        // Same rules as menu links: no backslashes (`/\evil.com` is read as `//evil.com`), no control characters.
        return MenuItem::isSafeUrl($url);
    }

    public static function clean(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return self::isSafe($url) ? trim($url) : null;
    }
}
