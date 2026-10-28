<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerGroup;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Customer group abilities map to the `customer_groups.*` permissions (RB-05). System groups cannot be deleted (CG-01).
 */
class CustomerGroupPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'customer_groups.view');
    }

    public function view(User $user, CustomerGroup $group): bool
    {
        return $this->allows($user, 'customer_groups.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'customer_groups.create');
    }

    public function update(User $user, CustomerGroup $group): bool
    {
        return $this->allows($user, 'customer_groups.update');
    }

    public function delete(User $user, CustomerGroup $group): bool
    {
        return $this->allows($user, 'customer_groups.delete') && ! $group->is_system;
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'customer_groups.delete');
    }
}
