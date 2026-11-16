<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ClientIpKey;
use Illuminate\Routing\Middleware\ThrottleRequests;
use RuntimeException;

/**
 * `throttle:N,M` with the visitor keyed by {@see ClientIpKey} (IPv6 by its /64 network) instead of the full address,
 * so one IPv6 host cannot pass a route limit by rotating addresses, and every route has its own counter (D-208). Registered as the `throttle` alias; named
 * limiters (`throttle:tg-session`) key themselves.
 */
class ThrottleByClientNetwork extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        if (! $route) {
            throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
        }

        // Laravel's numeric `throttle:N,M` signature has no route, so all such routes of a client would share ONE counter
        // (typing in the search box would use up the cart-add allowance). The route (its name, or its uri) is part of it (D-208).
        $routeKey = (string) ($route->getName() ?? $route->uri());

        if ($user = $request->user()) {
            return sha1($routeKey.'|'.$user->getAuthIdentifier());
        }

        return sha1($route->getDomain().'|'.$routeKey.'|'.ClientIpKey::of($request->ip()));
    }
}
