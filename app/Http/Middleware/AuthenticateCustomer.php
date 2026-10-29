<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer area guard (alias `customer.auth`): a visitor without a customer session is sent to the sign-in page and
 * brought back afterwards. Staff are not customers: the `web` guard never opens the cabinet (CA-32).
 */
class AuthenticateCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('customer')->guest()) {
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->guest(route('customer.login'));
        }

        return $next($request);
    }
}
