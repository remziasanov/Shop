<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Exceptions\StaffAccessException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Privilege escalation guard (RB-08): staff can hand out only rights they hold themselves.
 * Administrators hold every right, so for them nothing is restricted.
 */
final class EscalationGuard
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @param  iterable<string>  $permissions
     */
    public function canGrant(User $actor, iterable $permissions): bool
    {
        return $this->missing($actor, $permissions) === [];
    }

    /**
     * @param  iterable<string>  $permissions
     *
     * @throws StaffAccessException
     */
    public function assertCanGrant(User $actor, iterable $permissions): void
    {
        $missing = $this->missing($actor, $permissions);

        if ($missing !== []) {
            throw new StaffAccessException(__('roles.errors.escalation', [
                'permissions' => implode(', ', array_map(PermissionRegistry::label(...), $missing)),
            ]));
        }
    }

    /**
     * @throws StaffAccessException
     */
    public function assertCanAssign(User $actor, Role $role): void
    {
        $this->assertCanGrant($actor, $this->permissions->permissionsOfRole($role));
    }

    public function canAssign(User $actor, Role $role): bool
    {
        return $this->canGrant($actor, $this->permissions->permissionsOfRole($role));
    }

    /**
     * Whether the actor may change the account of `$target`: an administrator always, anyone else only when the
     * target's role grants nothing the actor lacks (otherwise changing the password would be an escalation).
     */
    public function canManageUser(User $actor, User $target): bool
    {
        if ($this->permissions->isAdministrator($actor)) {
            return true;
        }

        if ($target->role_id === null) {
            return true;
        }

        $role = Role::query()->find($target->role_id);

        return $role === null || $this->canAssign($actor, $role);
    }

    /**
     * Roles the actor may assign to users.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor): Collection
    {
        return Role::query()->orderBy('name')->get()->filter(fn (Role $role): bool => $this->canAssign($actor, $role))->values();
    }

    /**
     * @param  iterable<string>  $permissions
     * @return list<string>
     */
    private function missing(User $actor, iterable $permissions): array
    {
        if ($this->permissions->isAdministrator($actor)) {
            return [];
        }

        $own = array_flip($this->permissions->permissionsOf($actor));
        $missing = [];

        foreach ($permissions as $permission) {
            if (! isset($own[$permission])) {
                $missing[] = $permission;
            }
        }

        return array_values(array_unique($missing));
    }
}
