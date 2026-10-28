<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\AuditAction;
use App\Exceptions\StaffAccessException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and deletes staff accounts (RB-07, RB-08, RB-10, RB-12). Rights and integrity rules are
 * enforced here, so they hold for every caller and not only for the Filament forms (RB-11).
 */
final class StaffAccountService
{
    private const FIELDS = ['name', 'email', 'password', 'role_id', 'is_active'];

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly EscalationGuard $escalation,
        private readonly AdminGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, email, password, role_id, is_active
     *
     * @throws AuthorizationException
     * @throws StaffAccessException
     */
    public function create(array $data, User $actor): User
    {
        $this->authorize($actor, 'users.create');

        $role = Role::query()->findOrFail((int) ($data['role_id'] ?? 0));
        $this->escalation->assertCanAssign($actor, $role);

        return DB::transaction(function () use ($data, $role, $actor): User {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role_id' => $role->getKey(),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ])->save();

            $this->audit->record(AuditAction::UserCreated, $actor, $user, ['role' => $role->code, 'active' => $user->is_active]);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws StaffAccessException
     * @throws ValidationException
     */
    public function update(User $record, array $data, User $actor): User
    {
        $this->authorize($actor, 'users.update');

        if (! $record->is($actor) && ! $this->escalation->canManageUser($actor, $record)) {
            throw new StaffAccessException(__('roles.errors.cannot_manage_user'));
        }

        $data = array_intersect_key($data, array_flip(self::FIELDS));

        return DB::transaction(function () use ($record, $data, $actor): User {
            $this->guard->lockAdmins();

            /** @var User $record */
            $record = User::query()->whereKey($record->getKey())->firstOrFail();

            $this->guard->assertCanChange($record, $actor, $data);

            $fromRole = $record->role_id;
            $wasActive = $record->is_active;

            if (isset($data['role_id']) && (int) $data['role_id'] !== $fromRole) {
                $this->escalation->assertCanAssign($actor, Role::query()->findOrFail((int) $data['role_id']));
            }

            $record->fill(array_diff_key($data, ['role_id' => 1, 'is_active' => 1]));

            if (isset($data['role_id'])) {
                $record->role_id = (int) $data['role_id'];
            }

            if (array_key_exists('is_active', $data)) {
                $record->is_active = (bool) $data['is_active'];
            }

            $changed = array_keys($record->getDirty());

            if ($changed === []) {
                return $record;
            }

            $record->save();

            if ($record->role_id !== $fromRole) {
                $this->audit->record(AuditAction::UserRoleChanged, $actor, $record, [
                    'from' => $this->roleCode($fromRole),
                    'to' => $this->roleCode($record->role_id),
                ]);
            }

            if ($record->is_active !== $wasActive) {
                $this->audit->record($record->is_active ? AuditAction::UserActivated : AuditAction::UserDeactivated, $actor, $record);
            }

            $other = array_values(array_diff($changed, ['role_id', 'is_active', 'updated_at']));

            if ($other !== []) {
                $this->audit->record(AuditAction::UserUpdated, $actor, $record, ['fields' => $other]);
            }

            return $record;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws StaffAccessException
     */
    public function delete(User $record, User $actor): void
    {
        $this->authorize($actor, 'users.delete');

        if (! $this->escalation->canManageUser($actor, $record)) {
            throw new StaffAccessException(__('roles.errors.cannot_manage_user'));
        }

        DB::transaction(function () use ($record, $actor): void {
            $this->guard->lockAdmins();

            if ($record->is($actor)) {
                throw new StaffAccessException(__('shop.users.errors.self_lockout'));
            }

            if ($this->guard->isLastActiveAdmin($record)) {
                throw new StaffAccessException(__('shop.users.errors.last_admin'));
            }

            $this->audit->record(AuditAction::UserDeleted, $actor, $record, ['role' => $this->roleCode($record->role_id)]);
            $record->delete();
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

    private function roleCode(?int $roleId): ?string
    {
        return $roleId === null ? null : Role::query()->whereKey($roleId)->value('code');
    }
}
