<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\CustomerAnonymizing;
use App\Events\CustomerEmailVerified;
use App\Events\OrderStatusChanged;
use App\Listeners\EraseWholesaleApplicationData;
use App\Listeners\LinkGuestOrders;
use App\Listeners\NotifyCustomerOfStatusChange;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the customer-account events (stage 9): guest requests are linked to a confirmed address (CA-14), status
 * changes appear in the cabinet (CA-18), the anonymization erases wholesale applications (CA-17).
 *
 * The listener methods are not called `handle`, so Laravel's automatic event discovery does not register them a second time.
 */
class CustomerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CustomerEmailVerified::class, [LinkGuestOrders::class, 'onEmailVerified']);
        Event::listen(CustomerAnonymizing::class, [EraseWholesaleApplicationData::class, 'onAnonymizing']);
        Event::listen(OrderStatusChanged::class, [NotifyCustomerOfStatusChange::class, 'onStatusChanged']);
    }
}
