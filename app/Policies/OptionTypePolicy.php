<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OptionType;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Abilities on options map to the `option_types.*` permissions (RB-05, TZ 25.4, VR-26).
 */
class OptionTypePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'option_types.view');
    }

    public function view(User $user, OptionType $type): bool
    {
        return $this->allows($user, 'option_types.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'option_types.create');
    }

    public function update(User $user, OptionType $type): bool
    {
        return $this->allows($user, 'option_types.update');
    }

    public function delete(User $user, OptionType $type): bool
    {
        return $this->allows($user, 'option_types.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'option_types.delete');
    }
}
