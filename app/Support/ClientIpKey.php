<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The client address as a rate-limit key: an IPv4 address as it is, an IPv6 address reduced to its /64 network.
 * One IPv6 host usually controls a whole /64, so counting single IPv6 addresses would let it pass every per-IP limit
 * by changing the address (security audit SEC-01, SEC-02).
 */
final class ClientIpKey
{
    public static function of(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'unknown';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return $ip;
        }

        // An IPv4-mapped IPv6 address (::ffff:a.b.c.d) is the IPv4 client itself.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return ($network === false ? $ip : $network).'/64';
    }
}
