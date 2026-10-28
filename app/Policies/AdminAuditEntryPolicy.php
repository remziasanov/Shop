<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminAuditEntry;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * The action journal is read-only (RB-12): `system.audit_log.view`.
 */
class AdminAuditEntryPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'system.audit_log.view');
    }

    public function view(User $user, AdminAuditEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AdminAuditEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, AdminAuditEntry $entry): bool
    {
        return false;
    }
}
