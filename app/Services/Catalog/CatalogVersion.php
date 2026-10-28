<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;

/**
 * The catalog version (TZ 26.3): a token in the file cache that is part of the key of every cache that depends on
 * the catalog, a customer group, menus or pages (`home:{group}:{version}`, `access:{group}:{version}`, …). Bumping it
 * makes all those entries unreadable at once; they are removed by their TTL. No cache tags are needed.
 *
 * Bumped when products, categories, groups, pages, menu items, settings or prices change and when an import finishes
 * (see CatalogVersionServiceProvider and HomePageRenderer::forget()).
 */
final class CatalogVersion
{
    public const KEY = 'catalog:version';

    public static function current(): string
    {
        $version = Cache::get(self::KEY);

        if (is_string($version) && $version !== '') {
            return $version;
        }

        // Missing (fresh or flushed cache): start a new generation.
        return self::bump();
    }

    /**
     * Starts a new generation and returns its token. Microseconds make tokens of two bumps differ; a lost race
     * between processes only means one of the two tokens wins, both are new.
     */
    public static function bump(): string
    {
        $previous = Cache::get(self::KEY);
        $token = (string) (int) (microtime(true) * 1_000_000);

        if ($token === $previous) {
            $token .= 'a';
        }

        Cache::forever(self::KEY, $token);

        return $token;
    }
}
