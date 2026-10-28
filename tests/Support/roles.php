<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;

/**
 * Id of a system role created by the roles migration.
 */
function roleId(SystemRole $role): int
{
    return (int) Role::query()->where('code', $role->value)->value('id');
}

/**
 * An active staff member whose own role holds exactly these permissions.
 *
 * @param  list<string>  $permissions
 */
function staffWith(array $permissions): User
{
    return User::factory()->withPermissions($permissions)->create();
}
