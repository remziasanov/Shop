<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Filament\Auth\Http\Controllers\LogoutController;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

/**
 * Staff sign-out of the admin panel (CA-32): the panel's own controller invalidates the whole session, which would also
 * end a customer session of the same browser. Here the session is invalidated only when no customer is signed in;
 * otherwise only the CSRF token is renewed. Bound over the Filament controller in the AppServiceProvider.
 */
class StaffLogoutController extends LogoutController
{
    public function __invoke(): LogoutResponse
    {
        Filament::auth()->logout();

        if (Auth::guard('customer')->check()) {
            session()->regenerateToken();
        } else {
            session()->invalidate();
            session()->regenerateToken();
        }

        return app(LogoutResponse::class);
    }
}
