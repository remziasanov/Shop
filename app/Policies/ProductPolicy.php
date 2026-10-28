<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Catalog abilities map to the `products.*` permissions; the bulk base price change is `prices.bulk`, the other
 * bulk actions are `products.bulk` (RB-05, TZ 25.4).
 */
class ProductPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->allows($user, 'products.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->allows($user, 'products.update');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->allows($user, 'products.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'products.delete');
    }

    public function restore(User $user, Product $product): bool
    {
        return $this->allows($user, 'products.update');
    }

    public function restoreAny(User $user): bool
    {
        return $this->allows($user, 'products.update');
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $this->allows($user, 'products.delete');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->allows($user, 'products.delete');
    }

    /**
     * Bulk actions of the product list except the price change (AD-42).
     */
    public function bulk(User $user): bool
    {
        return $this->allows($user, 'products.bulk');
    }

    /**
     * Bulk change of base prices (AD-42).
     */
    public function changePrices(User $user): bool
    {
        return $this->allows($user, 'prices.bulk');
    }
}
