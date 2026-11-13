<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromoCodeBatch;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Code batches (PM-15, PM-16): everything about a batch needs `promo_codes.generate`; batches are never edited or
 * deleted by hand (their codes are managed as promo codes).
 */
class PromoCodeBatchPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'promo_codes.generate');
    }

    public function view(User $user, PromoCodeBatch $batch): bool
    {
        return $this->allows($user, 'promo_codes.generate');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'promo_codes.generate');
    }

    public function update(User $user, PromoCodeBatch $batch): bool
    {
        return $this->allows($user, 'promo_codes.generate');
    }

    public function delete(User $user, PromoCodeBatch $batch): bool
    {
        return false;
    }
}
