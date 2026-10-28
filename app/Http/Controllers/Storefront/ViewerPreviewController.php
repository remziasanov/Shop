<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Access\ViewerContext;
use App\Services\Users\PermissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Просмотреть витрину как…» (CG-18): a signed-in staff member with the right to see customer groups looks at the
 * storefront as a group. It only sets a flag in the staff session; no customer is signed in and nothing is ordered
 * under that identity. Pages of a preview are private and not indexable (see ResolveViewer).
 */
class ViewerPreviewController extends Controller
{
    public function enter(Request $request, CustomerGroup $group, PermissionService $permissions): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_active && $permissions->allows($user, 'customer_groups.view'), 403);

        $request->session()->put(ViewerContext::PREVIEW_KEY, $group->id);

        return redirect()->route('home');
    }

    public function exit(Request $request): RedirectResponse
    {
        $request->session()->forget(ViewerContext::PREVIEW_KEY);

        return redirect()->route('home');
    }
}
