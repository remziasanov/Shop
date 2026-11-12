<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OptionValue;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Option values are managed inside an option, so their abilities follow `option_types.*` (VR-26).
 */
class OptionValuePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'option_types.view');
    }

    public function view(User $user, OptionValue $value): bool
    {
        return $this->allows($user, 'option_types.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'option_types.update');
    }

    public function update(User $user, OptionValue $value): bool
    {
        return $this->allows($user, 'option_types.update');
    }

    public function delete(User $user, OptionValue $value): bool
    {
        return $this->allows($user, 'option_types.update');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'option_types.update');
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, 'option_types.update');
    }
}
