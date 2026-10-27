<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Concerns;

use App\Http\Middleware\StorefrontOpen;
use App\Settings\GeneralSettings;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Closed mode (SF-05, D-057) for storefront Livewire components. The Livewire update endpoint is exempt from the
 * {@see StorefrontOpen} middleware (the admin panel uses it), so every storefront component checks the shop state
 * itself, on mount and on every later request, and answers 503 to anyone but active staff.
 */
trait RequiresOpenShop
{
    public function bootRequiresOpenShop(): void
    {
        if (app(GeneralSettings::class)->shop_open || StorefrontOpen::isActiveStaff(auth()->user())) {
            return;
        }

        throw new HttpResponseException(StorefrontOpen::closedResponse());
    }
}
