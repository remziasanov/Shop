<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Protects administrator accounts from lock-out (RB-07, RB-08): nobody can change their own role or activity,
 * and the last active administrator can never be demoted, deactivated or deleted.
 */
class AdminGuard
{
    public function __construct(private readonly PermissionService $permissions) {}

    public function isLastActiveAdmin(User $user): bool
    {
        if (! $user->exists || ! $user->is_active || ! $this->permissions->hasAdministratorRole($user)) {
            return false;
        }

        return ! $this->activeAdmins()->whereKeyNot($user->getKey())->exists();
    }

    /**
     * Role and activity flags of this account are locked in the form.
     */
    public function isLocked(User $record, ?User $actor): bool
    {
        return ($actor !== null && $record->is($actor)) || $this->isLastActiveAdmin($record);
    }

    public function canDelete(User $record, ?User $actor): bool
    {
        return ($actor === null || ! $record->is($actor)) && ! $this->isLastActiveAdmin($record);
    }

    /**
     * Server-side check of submitted values for an existing account.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function assertCanChange(User $record, ?User $actor, array $data): void
    {
        $roleId = array_key_exists('role_id', $data) && $data['role_id'] !== null ? (int) $data['role_id'] : $record->role_id;
        $active = (bool) ($data['is_active'] ?? $record->is_active);
        $roleChanges = $roleId !== $record->role_id;
        $demotes = $this->permissions->hasAdministratorRole($record) && $roleId !== $this->adminRoleId();
        $deactivates = $record->is_active && ! $active;

        if (! $roleChanges && ! $deactivates) {
            return;
        }

        if ($actor !== null && $record->is($actor)) {
            throw ValidationException::withMessages(['data.role_id' => __('shop.users.errors.self_lockout')]);
        }

        if (($demotes || $deactivates) && $this->isLastActiveAdmin($record)) {
            throw ValidationException::withMessages(['data.role_id' => __('shop.users.errors.last_admin')]);
        }
    }

    /**
     * Lock the active administrators for the rest of the transaction, so two simultaneous changes cannot leave none.
     */
    public function lockAdmins(): void
    {
        $this->activeAdmins()->lockForUpdate()->get(['id']);
    }

    public function adminRoleId(): ?int
    {
        $id = Role::query()->where('code', SystemRole::Admin->value)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @return Builder<User>
     */
    private function activeAdmins(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereIn('role_id', Role::query()->where('code', SystemRole::Admin->value)->select('id'));
    }
}
