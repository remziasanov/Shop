<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Staff roles (RB-09): `roles.*`. System roles cannot be deleted and a role cannot be edited by its own members
 * (RB-07, RB-08); the service repeats these rules.
 */
class RolePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allows($user, 'roles.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'roles.create');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->allows($user, 'roles.update') && (int) $user->role_id !== (int) $role->getKey();
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->allows($user, 'roles.delete') && ! $role->is_system;
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'roles.delete');
    }
}
