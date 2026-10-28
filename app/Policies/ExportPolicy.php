<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ExportType;
use App\Models\Export;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use App\Services\Users\PermissionService;

/**
 * Exports (TZ 6.13, 25.4): `exports.view` opens the journal, `exports.create` starts a catalog export. A file is
 * available to its owner and to administrators; the owner of a request export needs only `orders.export`.
 */
class ExportPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'exports.view');
    }

    public function view(User $user, Export $export): bool
    {
        if (app(PermissionService::class)->isAdministrator($user)) {
            return $this->allows($user, 'exports.view');
        }

        if ($export->user_id !== $user->id) {
            return false;
        }

        return $this->allows($user, 'exports.view')
            || ($export->type === ExportType::Orders && $this->allows($user, 'orders.export'));
    }

    public function download(User $user, Export $export): bool
    {
        return $this->view($user, $export);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'exports.create');
    }

    public function update(User $user, Export $export): bool
    {
        return false;
    }

    public function delete(User $user, Export $export): bool
    {
        return false;
    }
}
