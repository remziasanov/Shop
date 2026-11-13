<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The positions or the amounts of an order were changed in the admin editor (OE-09). Dispatched after commit; totals
 * are integer kopecks. `$notifyCustomer` is the «Уведомить покупателя» checkbox of the form. Internal change: no
 * Telegram message.
 */
final class OrderItemsChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
        public readonly int $oldTotal,
        public readonly int $newTotal,
        public readonly bool $notifyCustomer,
        public readonly string $batchUuid,
        public readonly ?User $user = null,
    ) {}
}
