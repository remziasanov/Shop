<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use App\Services\Users\AdminGuard;
use App\Services\Users\EscalationGuard;

/**
 * Staff accounts (TZ 6.13, 25.4): `users.*`. Nobody manages an account that holds more rights than they do (RB-08),
 * nobody deletes themselves, and the last active administrator can never be deleted (RB-07).
 */
class UserPolicy
{
    use ChecksPermissions;

    public function __construct(
        private readonly AdminGuard $guard,
        private readonly EscalationGuard $escalation,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $this->allows($user, 'users.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $this->allows($user, 'users.update')
            && ($model->is($user) || $this->escalation->canManageUser($user, $model));
    }

    public function delete(User $user, User $model): bool
    {
        return $this->allows($user, 'users.delete')
            && $this->guard->canDelete($model, $user)
            && $this->escalation->canManageUser($user, $model);
    }
}
