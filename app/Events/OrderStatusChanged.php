<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The status of a request changed (`$bySystem` = made by the system, e.g. reservation expiry, and then `$user` is null). Dispatched after commit.
 */
final class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly ?User $user = null,
        public readonly bool $bySystem = false,
    ) {}
}
