<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ClientIpKey;
use Illuminate\Routing\Middleware\ThrottleRequests;
use RuntimeException;

/**
 * `throttle:N,M` with the visitor keyed by {@see ClientIpKey} (IPv6 by its /64 network) instead of the full address,
 * so one IPv6 host cannot pass a route limit by rotating addresses (D-208). Registered as the `throttle` alias; named
 * limiters (`throttle:tg-session`) key themselves.
 */
class ThrottleByClientNetwork extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        if ($user = $request->user()) {
            return sha1((string) $user->getAuthIdentifier());
        }

        if ($route = $request->route()) {
            return sha1($route->getDomain().'|'.ClientIpKey::of($request->ip()));
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }
}
