<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Administrators and managers both manage categories (TZ 6.13); inactive users manage nothing.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Category $category): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Category $category): bool
    {
        return $user->is_active;
    }

    public function delete(User $user, Category $category): bool
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
