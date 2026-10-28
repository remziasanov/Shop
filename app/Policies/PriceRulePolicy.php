<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceRule;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Price rules (TZ 6.13, 25.4): `price_rules.view|create|update|delete`; managers get view only by default.
 */
class PriceRulePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'price_rules.view');
    }

    public function view(User $user, PriceRule $rule): bool
    {
        return $this->allows($user, 'price_rules.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'price_rules.create');
    }

    public function update(User $user, PriceRule $rule): bool
    {
        return $this->allows($user, 'price_rules.update');
    }

    public function delete(User $user, PriceRule $rule): bool
    {
        return $this->allows($user, 'price_rules.delete');
    }
}
