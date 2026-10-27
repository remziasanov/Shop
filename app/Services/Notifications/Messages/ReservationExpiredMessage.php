<?php

declare(strict_types=1);

namespace App\Services\Notifications\Messages;

use App\Models\Order;

final class ReservationExpiredMessage extends OrderMessage
{
    public function render(Order $order): string
    {
        return implode("\n", [$this->heading($order, 'notifications.telegram.expired'), '', ...$this->body($order)]);
    }
}
