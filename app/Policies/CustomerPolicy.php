<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Staff abilities over customers (CA-21, RB-05): the `customers.*` permissions. Customers are created by registration,
 * never in the admin, and never deleted: anonymization replaces deletion. A customer who is already anonymized is not
 * changed any more.
 */
class CustomerPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->allows($user, 'customers.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $customer->anonymized_at === null && $this->allows($user, 'customers.update');
    }

    public function changeGroup(User $user, Customer $customer): bool
    {
        return $customer->anonymized_at === null && $this->allows($user, 'customers.change_group');
    }

    public function changeGroupAny(User $user): bool
    {
        return $this->allows($user, 'customers.change_group');
    }

    public function block(User $user, Customer $customer): bool
    {
        return $customer->anonymized_at === null && $this->allows($user, 'customers.block');
    }

    public function anonymize(User $user, Customer $customer): bool
    {
        return $customer->anonymized_at === null && $this->allows($user, 'customers.anonymize');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
