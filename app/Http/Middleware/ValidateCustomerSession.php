<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Customers\CustomerAuthService;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends stale customer sessions (CA-31, D-089). The session keeps the `auth_version` the customer signed in with; when
 * the account's value is higher (password reset or change, e-mail change, block, deletion request) the session is
 * dropped on its next request. Works with the file session driver, where sessions cannot be listed.
 *
 * A session restored from the «remember me» cookie has no stored value yet: it takes the current one (the cookie
 * itself dies with the rotation of the remember token that every version bump performs). Blocked and anonymized
 * accounts are dropped at once.
 *
 * Only the `customer` guard is touched; a staff session of the same browser stays signed in (CA-32). Runs on every web
 * request, including Livewire updates, and does nothing (no query) for visitors without a customer session.
 */
class ValidateCustomerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        /** @var SessionGuard $guard */
        $guard = Auth::guard('customer');
        $customer = $guard->user();

        if ($customer === null) {
            return $next($request);
        }

        $session = $request->session();
        $stored = $session->get(CustomerAuthService::SESSION_VERSION_KEY);

        $current = match (true) {
            $stored === null => $guard->viaRemember(),
            default => (int) $stored === $customer->auth_version,
        };

        if ($current && ! $customer->canSignIn()) {
            $current = false;
        }

        if ($current) {
            if ($stored === null) {
                $session->put(CustomerAuthService::SESSION_VERSION_KEY, $customer->auth_version);
            }

            return $next($request);
        }

        // Not logout(): that would rotate the remember token and so end the customer's valid sessions on other devices.
        $session->forget([$guard->getName(), CustomerAuthService::SESSION_VERSION_KEY]);
        $guard->forgetUser();
        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        return $next($request);
    }
}
