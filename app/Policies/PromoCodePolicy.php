<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromoCode;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Promo codes (TZ 22.4, 25.4): `promo_codes.view|create|update|delete`; managers get view only by default.
 * A code with redemptions can never be deleted, only deactivated (PM-19).
 */
class PromoCodePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'promo_codes.view');
    }

    public function view(User $user, PromoCode $code): bool
    {
        return $this->allows($user, 'promo_codes.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'promo_codes.create');
    }

    public function update(User $user, PromoCode $code): bool
    {
        return $this->allows($user, 'promo_codes.update');
    }

    public function delete(User $user, PromoCode $code): bool
    {
        return $this->allows($user, 'promo_codes.delete') && ! $code->redemptions()->exists();
    }
}
