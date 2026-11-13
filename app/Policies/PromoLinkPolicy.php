<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromoLink;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Promo links (TZ 22.4, 25.4): `promo_links.view|create|update|delete`; managers get view only by default.
 */
class PromoLinkPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'promo_links.view');
    }

    public function view(User $user, PromoLink $link): bool
    {
        return $this->allows($user, 'promo_links.view');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'promo_links.create');
    }

    public function update(User $user, PromoLink $link): bool
    {
        return $this->allows($user, 'promo_links.update');
    }

    public function delete(User $user, PromoLink $link): bool
    {
        return $this->allows($user, 'promo_links.delete');
    }
}
