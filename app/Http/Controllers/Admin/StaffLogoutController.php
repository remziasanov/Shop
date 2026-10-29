<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Access\ViewerContext;
use Filament\Auth\Http\Controllers\LogoutController;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

/**
 * Staff sign-out of the admin panel (CA-32): the panel's own controller invalidates the whole session, which would also
 * end a customer session of the same browser. Here the session is invalidated only when no customer is signed in;
 * otherwise the session id and the CSRF token are renewed and the staff-only keys (customer-group preview, CG-18) are dropped. Bound over the Filament controller in the AppServiceProvider.
 */
class StaffLogoutController extends LogoutController
{
    public function __invoke(): LogoutResponse
    {
        Filament::auth()->logout();

        if (Auth::guard('customer')->check()) {
            // The customer session stays, but it must not carry staff state on: the session id is renewed (the old id,
            // known to the staff browser's history, dies) and the staff-only keys go.
            session()->forget([ViewerContext::PREVIEW_KEY, 'password_hash_web']);
            session()->migrate(true);
            session()->regenerateToken();
        } else {
            session()->invalidate();
            session()->regenerateToken();
        }

        return app(LogoutResponse::class);
    }
}
