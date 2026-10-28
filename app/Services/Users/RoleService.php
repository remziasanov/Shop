<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\AuditAction;
use App\Exceptions\StaffAccessException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates, changes, copies and deletes staff roles (RB-01, RB-07, RB-08, RB-12, RB-13). The acting user is
 * required and checked here, so the rules hold for any caller, not only for the admin forms (RB-11).
 */
final class RoleService
{
    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,31}$/';

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly EscalationGuard $escalation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, description?: string|null}  $attributes
     * @param  list<string>  $permissions
     *
     * @throws AuthorizationException
     * @throws StaffAccessException
     */
    public function create(array $attributes, array $permissions, User $actor): Role
    {
        $this->authorize($actor, 'roles.create');

        $code = $this->validCode((string) $attributes['code'], null);
        $permissions = PermissionRegistry::normalize($permissions);

        $this->escalation->assertCanGrant($actor, $permissions);

        return DB::transaction(function () use ($attributes, $code, $permissions, $actor): Role {
            $role = Role::query()->create([
                'code' => $code,
                'name' => (string) $attributes['name'],
                'description' => $this->blankToNull($attributes['description'] ?? null),
                'is_system' => false,
                'permissions_version' => 1,
            ]);

            $this->store($role, $permissions);
            $this->audit->record(AuditAction::RoleCreated, $actor, $role, ['code' => $role->code, 'permissions' => $permissions]);

            return $role;
        });
    }

    /**
     * @param  array{code?: string, name?: string, description?: string|null}  $attributes
     * @param  list<string>|null  $permissions  null leaves the permissions untouched
     *
     * @throws AuthorizationException
     * @throws StaffAccessException
     */
    public function update(Role $role, array $attributes, ?array $permissions, User $actor): Role
    {
        $this->authorize($actor, 'roles.update');

        if ($actor->role_id !== null && (int) $actor->role_id === (int) $role->getKey()) {
            throw new StaffAccessException(__('roles.errors.own_role'));
        }

        if ($role->is_system && isset($attributes['code']) && $attributes['code'] !== $role->code) {
            throw new StaffAccessException(__('roles.errors.system_code'));
        }

        $stored = $role->storedPermissions();
        $new = $permissions !== null ? PermissionRegistry::normalize($permissions) : null;

        if ($role->isAdministrator() && $new !== null && $new !== []) {
            // The Administrator role stores nothing and allows everything (RB-02, RB-07).
            throw new StaffAccessException(__('roles.errors.admin_immutable'));
        }

        $added = $new !== null ? array_values(array_diff($new, $stored)) : [];
        $removed = $new !== null && ! $role->isAdministrator() ? array_values(array_diff($stored, $new)) : [];

        $this->escalation->assertCanGrant($actor, $added);

        $code = isset($attributes['code']) && ! $role->is_system ? $this->validCode((string) $attributes['code'], $role) : $role->code;

        DB::transaction(function () use ($role, $attributes, $code, $new, $added, $removed, $actor): void {
            $role->fill([
                'code' => $code,
                'name' => (string) ($attributes['name'] ?? $role->name),
                'description' => array_key_exists('description', $attributes) ? $this->blankToNull($attributes['description']) : $role->description,
            ]);

            $changed = array_keys($role->getDirty());

            if ($changed !== []) {
                $role->save();
                $this->audit->record(AuditAction::RoleUpdated, $actor, $role, ['code' => $role->code, 'fields' => $changed]);
            }

            if ($new !== null && ($added !== [] || $removed !== [])) {
                $this->store($role, $new);
                $this->audit->record(AuditAction::RolePermissionsChanged, $actor, $role, [
                    'code' => $role->code,
                    'added' => $added,
                    'removed' => $removed,
                ]);
            }
        });

        return $role->refresh();
    }

    /**
     * A copy of the role with the same permissions (RB-09).
     *
     * @throws AuthorizationException
     * @throws StaffAccessException
     */
    public function duplicate(Role $source, User $actor): Role
    {
        $name = mb_substr(__('roles.copy_name', ['name' => $source->name]), 0, 255);

        return $this->create(
            ['code' => $this->freeCode($source->code), 'name' => $name, 'description' => $source->description],
            $this->permissions->permissionsOfRole($source),
            $actor,
        );
    }

    /**
     * @throws AuthorizationException
     * @throws StaffAccessException
     */
    public function delete(Role $role, User $actor): void
    {
        $this->authorize($actor, 'roles.delete');

        if ($role->is_system) {
            throw new StaffAccessException(__('roles.errors.system_delete'));
        }

        $names = User::query()->where('role_id', $role->getKey())->orderBy('name')->limit(10)->pluck('name')->all();

        if ($names !== []) {
            $total = User::query()->where('role_id', $role->getKey())->count();

            throw new StaffAccessException(__('roles.errors.has_users', [
                'count' => $total,
                'users' => implode(', ', $names).($total > count($names) ? '…' : ''),
            ]));
        }

        DB::transaction(function () use ($role, $actor): void {
            $this->audit->record(AuditAction::RoleDeleted, $actor, $role, ['code' => $role->code]);
            $role->delete();
        });

        $this->permissions->flush();
    }

    /**
     * Replace the permissions without any authorization or audit. For factories, seeding and tests only.
     *
     * @param  list<string>  $permissions
     */
    public function syncPermissionsUnchecked(Role $role, array $permissions): void
    {
        $this->store($role, PermissionRegistry::normalize($permissions));
    }

    /**
     * @param  list<string>  $permissions  already normalized
     */
    private function store(Role $role, array $permissions): void
    {
        DB::transaction(function () use ($role, $permissions): void {
            DB::table('role_permissions')->where('role_id', $role->getKey())->delete();

            if ($permissions !== [] && ! $role->isAdministrator()) {
                DB::table('role_permissions')->insert(array_map(
                    fn (string $permission): array => ['role_id' => $role->getKey(), 'permission' => $permission],
                    $permissions,
                ));
            }

            // RB-13: a new version makes the change effective for every user of the role at once.
            $this->permissions->bump($role);
        });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @throws StaffAccessException
     */
    private function validCode(string $code, ?Role $ignore): string
    {
        $code = mb_strtolower(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new StaffAccessException(__('roles.errors.code_format'));
        }

        $taken = Role::query()
            ->where('code', $code)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->exists();

        if ($taken) {
            throw new StaffAccessException(__('roles.errors.code_taken'));
        }

        return $code;
    }

    private function freeCode(string $base): string
    {
        $base = mb_substr($base, 0, 24);
        $candidate = $base.'-copy';

        for ($i = 2; Role::query()->where('code', $candidate)->exists(); $i++) {
            $candidate = $base.'-copy'.$i;
        }

        return $candidate;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
