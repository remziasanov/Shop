<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PriceHistory;
use App\Models\User;

/**
 * The price history is read-only for administrators and managers (PR-06): nobody creates, edits or deletes rows.
 */
class PriceHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function view(User $user, PriceHistory $history): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PriceHistory $history): bool
    {
        return false;
    }

    public function delete(User $user, PriceHistory $history): bool
    {
        return false;
    }
}
