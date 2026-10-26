<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A request (order, reservation or inquiry) was created. Dispatched after the transaction commits (D-049);
 * stage 6 attaches mail and Telegram listeners.
 */
final class OrderCreated
{
    use Dispatchable;

    public function __construct(public readonly Order $order) {}
}
