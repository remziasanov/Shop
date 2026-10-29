<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WholesaleApplication;
use App\Policies\Concerns\ChecksPermissions;
use App\Services\Users\PermissionService;

/**
 * Wholesale applications (TZ 6.13, 25.4): viewing is `wholesale_applications.view`, the decision (approve / reject) is
 * `wholesale_applications.decide`. Returning a decided application to «new» is for the Administrator only (CG-22).
 * Applications are created by customers and never edited or deleted in the admin.
 */
class WholesaleApplicationPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'wholesale_applications.view');
    }

    public function view(User $user, WholesaleApplication $application): bool
    {
        return $this->allows($user, 'wholesale_applications.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WholesaleApplication $application): bool
    {
        return false;
    }

    public function delete(User $user, WholesaleApplication $application): bool
    {
        return false;
    }

    public function decide(User $user, WholesaleApplication $application): bool
    {
        return $this->allows($user, 'wholesale_applications.decide');
    }

    public function returnToNew(User $user, WholesaleApplication $application): bool
    {
        return app(PermissionService::class)->isAdministrator($user);
    }
}
