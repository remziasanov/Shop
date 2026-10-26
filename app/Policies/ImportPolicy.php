<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Import;
use App\Models\User;

/**
 * Imports (TZ 6.13): administrators and managers run imports and see the whole journal (they share one catalog);
 * the «full synchronization» mode and deleting an import are for administrators only.
 */
class ImportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, Import $import): bool
    {
        return $this->staff($user);
    }

    public function create(User $user): bool
    {
        return $this->staff($user);
    }

    public function update(User $user, Import $import): bool
    {
        return false;
    }

    /**
     * Browser fallback (IM-10) and file downloads: same audience as viewing.
     */
    public function process(User $user, Import $import): bool
    {
        return $this->staff($user);
    }

    public function download(User $user, Import $import): bool
    {
        return $this->staff($user);
    }

    /**
     * «Полная синхронизация» deactivates products missing in the file: administrators only.
     */
    public function useFullSync(User $user): bool
    {
        return $this->staff($user) && $user->isAdmin();
    }

    public function delete(User $user, Import $import): bool
    {
        return $this->staff($user) && $user->isAdmin() && ! $import->status->isRunning();
    }

    private function staff(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
