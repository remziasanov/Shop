<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The only place where staff rights are checked (RB-04, TZ 25.8). Policies, settings pages, navigation, widgets,
 * table actions and services all call {@see allows()}.
 *
 * - an inactive user has no rights, a user without a role has none either;
 * - the Administrator role allows everything, including permissions added in later versions (RB-02);
 * - the permission set of any other role is loaded once per request and cached for 10 minutes under
 *   `perm:role:{id}:{permissions_version}`; every change of a role bumps `roles.permissions_version`, so the
 *   new set applies at once for all users of the role (RB-13).
 */
final class PermissionService
{
    public const CACHE_TTL_SECONDS = 600;

    /** @var array<int, array{administrator: bool, permissions: array<string, true>}|null> */
    private array $memo = [];

    public function allows(User $user, string $permission): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $set = $this->roleSet($user->role_id);

        if ($set === null) {
            return false;
        }

        return $set['administrator'] || isset($set['permissions'][$permission]);
    }

    /**
     * Shortcut for the signed-in staff member (navigation, widgets, pages); false for guests.
     */
    public function allowsCurrent(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->allows($user, $permission);
    }

    public function allowsAny(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the (active) user holds every one of the permissions.
     *
     * @param  iterable<string>  $permissions
     */
    public function allowsAll(User $user, iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->allows($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Active user with the Administrator role. Only for decisions that are about the role itself (the last
     * administrator, seeing every user's exports); feature access is always {@see allows()}.
     */
    public function isAdministrator(User $user): bool
    {
        return $user->is_active && $this->hasAdministratorRole($user);
    }

    /**
     * The user's role is the Administrator role, whether or not the account is active.
     */
    public function hasAdministratorRole(User $user): bool
    {
        return ($this->roleSet($user->role_id)['administrator'] ?? false) === true;
    }

    /**
     * Effective permissions of a user (all of the catalog for an administrator, none when inactive).
     *
     * @return list<string>
     */
    public function permissionsOf(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        $set = $this->roleSet($user->role_id);

        if ($set === null) {
            return [];
        }

        return $set['administrator'] ? PermissionRegistry::all() : array_keys($set['permissions']);
    }

    /**
     * Permissions a role grants (the whole catalog for the Administrator role), regardless of any user.
     *
     * @return list<string>
     */
    public function permissionsOfRole(Role $role): array
    {
        $set = $this->roleSet((int) $role->getKey());

        if ($set === null) {
            return [];
        }

        return $set['administrator'] ? PermissionRegistry::all() : array_keys($set['permissions']);
    }

    /**
     * Make a permission change of the role effective at once: the version is part of the cache key.
     */
    public function bump(Role $role): void
    {
        $oldVersion = (int) $role->permissions_version;

        DB::table('roles')->where('id', $role->getKey())->increment('permissions_version');
        $role->refresh();

        Cache::forget($this->cacheKey((int) $role->getKey(), $oldVersion));

        $this->flush();
    }

    /**
     * Forget the per-request memo (end of a request, queue iteration, tests that edit roles directly).
     */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * @return array{administrator: bool, permissions: array<string, true>}|null
     */
    private function roleSet(?int $roleId): ?array
    {
        if ($roleId === null) {
            return null;
        }

        if (array_key_exists($roleId, $this->memo)) {
            return $this->memo[$roleId];
        }

        $row = DB::table('roles')->where('id', $roleId)->first(['code', 'permissions_version']);

        if ($row === null) {
            return $this->memo[$roleId] = null;
        }

        if ((string) $row->code === SystemRole::Admin->value) {
            return $this->memo[$roleId] = ['administrator' => true, 'permissions' => []];
        }

        /** @var list<string> $stored */
        $stored = Cache::remember(
            $this->cacheKey($roleId, (int) $row->permissions_version),
            self::CACHE_TTL_SECONDS,
            fn (): array => PermissionRegistry::normalize(
                DB::table('role_permissions')->where('role_id', $roleId)->pluck('permission')->all(),
            ),
        );

        return $this->memo[$roleId] = ['administrator' => false, 'permissions' => array_fill_keys($stored, true)];
    }

    private function cacheKey(int $roleId, int $version): string
    {
        return 'perm:role:'.$roleId.':'.$version;
    }
}
