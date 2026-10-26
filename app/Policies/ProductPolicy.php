<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;

/**
 * Administrators and managers manage the catalog (TZ 6.13). The bulk price change is administrator-only.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->manages($user);
    }

    public function restoreAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $this->manages($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    /**
     * Bulk change of base prices (AD-42, TZ 6.13): administrators only.
     */
    public function changePrices(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Admin;
    }

    private function manages(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }
}
