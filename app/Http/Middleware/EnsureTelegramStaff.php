<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Telegram\MiniApp\StaffSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/tg/staff/*` (TS-05): the guard `telegram_staff` is the only way in, and it is re-checked on every request (TS-02):
 * still linked to this Telegram user, user active, `orders.view`, the feature on, the session younger than 8 hours.
 * While the request runs the default guard is `telegram_staff`, so policies and Livewire components read the staff member
 * from it (`Auth::shouldUse`); the previous default is restored afterwards, so a long-lived process or a test can never
 * take the Telegram sign-in for the admin one. Also a persistent middleware of Livewire: a failed check aborts the update
 * request (a redirect or an exception, both abort a Livewire request; a plain response would be dropped).
 */
class EnsureTelegramStaff
{
    public function __construct(private readonly StaffSessionService $staff) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $user = $this->staff->valid($session);

        if (! $user instanceof User) {
            $signedIn = Auth::guard(StaffSessionService::GUARD)->user() !== null;
            $this->staff->end($session);

            // A guest session goes back to the entry (it tries the staff mode again); a session that lost its right is refused.
            abort_if($signedIn, 403, __('telegram_staff.workplace.denied_text'));

            return redirect()->route('tg.entry', ['mode' => 'staff']);
        }

        $previous = Auth::getDefaultDriver();
        Auth::shouldUse(StaffSessionService::GUARD);

        try {
            $response = $next($request);
        } finally {
            Auth::shouldUse($previous);
        }

        // Personal pages: never stored by a proxy or the browser history of a shared device.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
