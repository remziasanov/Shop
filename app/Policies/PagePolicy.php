<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Page;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Page abilities map to the `pages.*` permissions (RB-05).
 */
class PagePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'pages.view');
    }

    public function view(User $user, Page $record): bool
    {
        return $this->allows($user, 'pages.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'pages.create');
    }

    public function update(User $user, Page $record): bool
    {
        return $this->allows($user, 'pages.update');
    }

    public function delete(User $user, Page $record): bool
    {
        return $this->allows($user, 'pages.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'pages.delete');
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, 'pages.update');
    }
}
