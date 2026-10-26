<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

/**
 * Requests (TZ 6.13): administrators and managers view, change status, extend, comment and export;
 * only administrators delete (OR-07). Requests are created by customers, never in the admin.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->staff($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Status change, reservation extension and the manager comment.
     */
    public function update(User $user, Order $order): bool
    {
        return $this->staff($user);
    }

    public function export(User $user): bool
    {
        return $this->staff($user);
    }

    public function delete(User $user, Order $order): bool
    {
        return $this->admin($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function restore(User $user, Order $order): bool
    {
        return $this->admin($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->admin($user);
    }

    public function forceDelete(User $user, Order $order): bool
    {
        return $this->admin($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->admin($user);
    }

    private function staff(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    private function admin(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Admin;
    }
}
