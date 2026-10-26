<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Export;
use App\Models\User;

/**
 * Administrators and managers start exports (TZ 6.13); a file is available to its owner and to administrators.
 */
class ExportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, Export $export): bool
    {
        return $this->staff($user) && ($user->isAdmin() || $export->user_id === $user->id);
    }

    public function download(User $user, Export $export): bool
    {
        return $this->view($user, $export);
    }

    public function create(User $user): bool
    {
        return $this->staff($user);
    }

    public function update(User $user, Export $export): bool
    {
        return false;
    }

    public function delete(User $user, Export $export): bool
    {
        return false;
    }

    private function staff(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
