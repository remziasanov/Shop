<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MenuItem;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * MenuItem abilities map to the `menu.*` permissions (RB-05).
 */
class MenuItemPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'menu.view');
    }

    public function view(User $user, MenuItem $record): bool
    {
        return $this->allows($user, 'menu.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'menu.create');
    }

    public function update(User $user, MenuItem $record): bool
    {
        return $this->allows($user, 'menu.update');
    }

    public function delete(User $user, MenuItem $record): bool
    {
        return $this->allows($user, 'menu.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'menu.delete');
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, 'menu.update');
    }
}
