<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttributeValue;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Dictionary values are managed inside a characteristic, so their abilities follow `attributes.*` (FL-02, FL-17).
 */
class AttributeValuePolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'attributes.view');
    }

    public function view(User $user, AttributeValue $value): bool
    {
        return $this->allows($user, 'attributes.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'attributes.update');
    }

    public function update(User $user, AttributeValue $value): bool
    {
        return $this->allows($user, 'attributes.update');
    }

    public function delete(User $user, AttributeValue $value): bool
    {
        return $this->allows($user, 'attributes.update');
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, 'attributes.update');
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, 'attributes.update');
    }
}
