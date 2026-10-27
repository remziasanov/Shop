<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\Users\AdminGuard;

/**
 * Only administrators manage users (TZ 6.13). An administrator cannot delete themselves, nor can the last active administrator be deleted.
 */
class UserPolicy
{
    public function __construct(private readonly AdminGuard $guard) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && $this->guard->canDelete($model, $user);
    }
}
