<?php

declare(strict_types=1);

namespace App\Services\Notifications\Messages;

use App\Models\Order;

final class ReservationExpiringMessage extends OrderMessage
{
    public function render(Order $order): string
    {
        return $this->compose($order, 'notifications.telegram.expiring');
    }
}
