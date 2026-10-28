<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Import;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Imports (TZ 6.13, 25.4): `imports.view` sees the journal and files, `imports.run` starts and processes imports,
 * `imports.full_sync` is the «full synchronization» mode. Deleting an import is the same elevated right.
 */
class ImportPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'imports.view');
    }

    public function view(User $user, Import $import): bool
    {
        return $this->allows($user, 'imports.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'imports.run');
    }

    public function update(User $user, Import $import): bool
    {
        return false;
    }

    /**
     * Browser fallback (IM-10): drives the import forward.
     */
    public function process(User $user, Import $import): bool
    {
        return $this->allows($user, 'imports.run');
    }

    public function download(User $user, Import $import): bool
    {
        return $this->allows($user, 'imports.view');
    }

    /**
     * «Полная синхронизация» deactivates products missing in the file.
     */
    public function useFullSync(User $user): bool
    {
        return $this->allows($user, 'imports.run') && $this->allows($user, 'imports.full_sync');
    }

    public function delete(User $user, Import $import): bool
    {
        return $this->allows($user, 'imports.full_sync') && ! $import->status->isRunning();
    }
}
