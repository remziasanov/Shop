<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;

/**
 * Administrators and managers both manage pages and menus (TZ 6.13); inactive users manage nothing.
 */
class MenuItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, MenuItem $record): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, MenuItem $record): bool
    {
        return $user->is_active;
    }

    public function delete(User $user, MenuItem $record): bool
    {
        return $user->is_active;
    }

    public function deleteAny(User $user): bool
    {
        return $user->is_active;
    }

    public function reorder(User $user): bool
    {
        return $user->is_active;
    }
}
