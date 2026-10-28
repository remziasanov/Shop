<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired synchronously by CustomerAnonymizer inside its transaction, before the customer row is wiped (CA-17).
 * Extension point: features that keep personal data of their own (wholesale applications, CG-20) erase it in a
 * listener. A listener must not be queued: a failure has to roll the whole anonymization back.
 */
final class CustomerAnonymizing
{
    use Dispatchable;

    public function __construct(public readonly Customer $customer) {}
}
