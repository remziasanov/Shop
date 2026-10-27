<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Host patterns accepted in production (D-057): the APP_URL host, its www./bare twin and SHOP_EXTRA_HOSTS.
 */
final class TrustedHosts
{
    /**
     * @return list<string> regular expressions (without delimiters) for TrustHosts
     */
    public static function patterns(): array
    {
        $host = self::canonical();

        if ($host === null) {
            return [];
        }

        $bare = preg_replace('/^www\./i', '', $host) ?? $host;
        $patterns = ['^'.preg_quote($bare).'$', '^www\.'.preg_quote($bare).'$'];

        foreach ((array) config('shop.extra_hosts', []) as $extra) {
            if (is_string($extra) && trim($extra) !== '') {
                $patterns[] = '^'.preg_quote(strtolower(trim($extra))).'$';
            }
        }

        return $patterns;
    }

    /**
     * The host of APP_URL (lower case), null when APP_URL has none.
     */
    public static function canonical(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * The www./bare twin of a host.
     */
    public static function twin(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;
    }

    /**
     * Hosts that are accepted but must be redirected to the canonical one: its twin and SHOP_EXTRA_HOSTS.
     *
     * @return list<string>
     */
    public static function redirectedHosts(string $canonical): array
    {
        $hosts = [self::twin($canonical)];

        foreach ((array) config('shop.extra_hosts', []) as $extra) {
            if (is_string($extra) && trim($extra) !== '') {
                $hosts[] = strtolower(trim($extra));
            }
        }

        return array_values(array_unique($hosts));
    }
}
