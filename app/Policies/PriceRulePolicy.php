<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PriceRule;
use App\Models\User;

/**
 * Price rules: administrators manage them, managers only view (TZ 6.13).
 */
class PriceRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->views($user);
    }

    public function view(User $user, PriceRule $rule): bool
    {
        return $this->views($user);
    }

    public function create(User $user): bool
    {
        return $this->administers($user);
    }

    public function update(User $user, PriceRule $rule): bool
    {
        return $this->administers($user);
    }

    public function delete(User $user, PriceRule $rule): bool
    {
        return $this->administers($user);
    }

    private function views(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    private function administers(User $user): bool
    {
        return $user->is_active && $user->role === UserRole::Admin;
    }
}
