<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

/**
 * Administrators and managers both manage pages and menus (TZ 6.13); inactive users manage nothing.
 */
class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Page $record): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Page $record): bool
    {
        return $user->is_active;
    }

    public function delete(User $user, Page $record): bool
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
