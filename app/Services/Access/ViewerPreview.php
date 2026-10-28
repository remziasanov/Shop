<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Users\PermissionService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Starts and ends the staff preview of the storefront «как группа» (CG-18). The preview is a flag in the staff session
 * ({@see ViewerContext::PREVIEW_KEY}); {@see ViewerContext} re-checks the right on every request.
 */
final class ViewerPreview
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function canPreview(?User $user): bool
    {
        return $user !== null && $user->is_active && $this->permissions->allows($user, 'customer_groups.view');
    }

    /**
     * @throws HttpResponseException 403 when the user may not preview
     */
    public function enter(?User $user, CustomerGroup $group, Session $session): void
    {
        abort_unless($this->canPreview($user), 403);

        $session->put(ViewerContext::PREVIEW_KEY, $group->getKey());
    }

    public function exit(Session $session): void
    {
        $session->forget(ViewerContext::PREVIEW_KEY);
    }
}
