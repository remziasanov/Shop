<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in and registration pages (alias `customer.guest`) are for visitors only: a signed-in customer goes to the cabinet.
 */
class RedirectIfCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('account');
        }

        return $next($request);
    }
}
