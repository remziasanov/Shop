<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Requests (TZ 6.13, 25.4): view, change status / extend / comment (`orders.update`), export and delete (OR-07)
 * are separate permissions. Requests are created by customers, never in the admin.
 */
class OrderPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->allows($user, 'orders.view');
    }

    /**
     * CA-28: the customer opens only requests that belong to the account (the cabinet answers 404 otherwise). Staff
     * permissions have nothing to do with it: the `customer` guard and the staff guard are separate (D-071).
     */
    public function viewAsCustomer(Customer $customer, Order $order): bool
    {
        return $order->customer_id !== null && $order->customer_id === $customer->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Status change, reservation extension and the manager comment.
     */
    public function update(User $user, Order $order): bool
    {
        return $this->allows($user, 'orders.update');
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'orders.export');
    }

    public function delete(User $user, Order $order): bool
    {
        return $this->allows($user, 'orders.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'orders.delete');
    }

    // Deleting settles stock (D-051), so a restored request would no longer hold it: restore is not supported.
    public function restore(User $user, Order $order): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Order $order): bool
    {
        return $this->allows($user, 'orders.delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->allows($user, 'orders.delete');
    }
}
