<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Variants are part of a product card, so their abilities follow `products.*`: viewing needs `products.view`, every
 * change `products.update` (VR-24).
 */
class ProductVariantPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'products.view');
    }

    public function view(User $user, ProductVariant $variant): bool
    {
        return $this->allows($user, 'products.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'products.update');
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $this->allows($user, 'products.update');
    }

    public function delete(User $user, ProductVariant $variant): bool
    {
        return $this->allows($user, 'products.update');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'products.update');
    }
}
