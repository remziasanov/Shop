<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CustomerEmailVerified;
use App\Services\Customers\OrderLinker;
use Throwable;

/**
 * CA-14: a confirmed address claims the guest requests placed with it. Runs synchronously; a failure is reported but
 * does not undo the confirmation (the next confirmation of the address links the rest).
 */
final class LinkGuestOrders
{
    public function __construct(private readonly OrderLinker $linker) {}

    public function onEmailVerified(CustomerEmailVerified $event): void
    {
        try {
            $this->linker->link($event->customer);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
