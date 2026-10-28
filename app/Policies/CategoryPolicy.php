<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Category abilities map to the `categories.*` permissions (RB-05).
 */
class CategoryPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'categories.view');
    }

    public function view(User $user, Category $record): bool
    {
        return $this->allows($user, 'categories.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'categories.create');
    }

    public function update(User $user, Category $record): bool
    {
        return $this->allows($user, 'categories.update');
    }

    public function delete(User $user, Category $record): bool
    {
        return $this->allows($user, 'categories.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'categories.delete');
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, 'categories.update');
    }
}
