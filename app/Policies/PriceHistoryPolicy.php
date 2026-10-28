<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceHistory;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * The price history is read-only (PR-06): `price_history.view`, nobody creates, edits or deletes rows.
 */
class PriceHistoryPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'price_history.view');
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
