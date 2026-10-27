<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Protects administrator accounts from lock-out: nobody can strip their own admin access,
 * and the last active administrator can never be demoted, deactivated or deleted.
 */
class AdminGuard
{
    public function isLastActiveAdmin(User $user): bool
    {
        if (! $user->exists || ! $user->isAdmin() || ! $user->is_active) {
            return false;
        }

        return ! User::query()
            ->where('role', UserRole::Admin->value)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    /**
     * Role and activity flags of this account are locked in the form.
     */
    public function isLocked(User $record, ?User $actor): bool
    {
        return ($actor !== null && $record->is($actor) && $record->isAdmin())
            || $this->isLastActiveAdmin($record);
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
        $role = $this->roleOf($data['role'] ?? $record->role);
        $active = (bool) ($data['is_active'] ?? $record->is_active);
        $demotes = $record->isAdmin() && $role !== UserRole::Admin;
        $deactivates = $record->is_active && ! $active;

        if (! $demotes && ! $deactivates) {
            return;
        }

        if ($actor !== null && $record->is($actor)) {
            throw ValidationException::withMessages(['role' => __('shop.users.errors.self_lockout')]);
        }

        if ($this->isLastActiveAdmin($record)) {
            throw ValidationException::withMessages(['role' => __('shop.users.errors.last_admin')]);
        }
    }

    private function roleOf(mixed $value): UserRole
    {
        return $value instanceof UserRole ? $value : UserRole::from((string) $value);
    }
}
