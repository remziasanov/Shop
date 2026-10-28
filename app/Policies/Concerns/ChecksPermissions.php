<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use App\Services\Users\PermissionService;

/**
 * Policies decide nothing themselves: every ability maps to a permission key of the registry (RB-05).
 */
trait ChecksPermissions
{
    protected function allows(User $user, string $permission): bool
    {
        return app(PermissionService::class)->allows($user, $permission);
    }
}
