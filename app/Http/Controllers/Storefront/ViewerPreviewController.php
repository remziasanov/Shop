<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Access\ViewerPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Просмотреть витрину как…» (CG-18): a signed-in staff member with the right to see customer groups looks at the
 * storefront as a group. It only sets a flag in the staff session; no customer is signed in and nothing is ordered
 * under that identity. Both routes are POST (CSRF-protected): entering or leaving changes the session. Pages of a
 * preview are private and not indexable (see ResolveViewer).
 */
class ViewerPreviewController extends Controller
{
    public function enter(Request $request, CustomerGroup $group, ViewerPreview $preview): RedirectResponse
    {
        $user = $request->user();

        $preview->enter($user instanceof User ? $user : null, $group, $request->session());

        return redirect()->route('home');
    }

    public function exit(Request $request, ViewerPreview $preview): RedirectResponse
    {
        $preview->exit($request->session());

        return redirect()->route('home');
    }
}
