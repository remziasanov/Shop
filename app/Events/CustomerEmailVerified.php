<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The customer proved ownership of their address: after the registration code and after a confirmed e-mail change
 * (TZ 17.6). Extension point: the guest-request linker (CA-14, OrderLinker) listens to this event.
 */
final class CustomerEmailVerified
{
    use Dispatchable;

    public function __construct(public readonly Customer $customer) {}
}
