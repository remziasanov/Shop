<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Attribute;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Abilities on characteristics map to the `attributes.*` permissions (RB-05, TZ 25.4, FL-17).
 */
class AttributePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'attributes.view');
    }

    public function view(User $user, Attribute $attribute): bool
    {
        return $this->allows($user, 'attributes.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'attributes.create');
    }

    public function update(User $user, Attribute $attribute): bool
    {
        return $this->allows($user, 'attributes.update');
    }

    public function delete(User $user, Attribute $attribute): bool
    {
        return $this->allows($user, 'attributes.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'attributes.delete');
    }
}
