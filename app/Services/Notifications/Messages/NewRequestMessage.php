<?php

declare(strict_types=1);

namespace App\Services\Notifications\Messages;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * The work chat message about a new request (NT-02) with the channel, the current status (TS-01) and, once a staff
 * member acted, the line «Подтвердил Иванов 14:32» (TS-03). The text is the same for the first send and every redraw.
 */
final class NewRequestMessage extends OrderMessage
{
    public function render(Order $order): string
    {
        return $this->compose($order, 'notifications.telegram.new', withStatus: true, doneLine: $this->doneLine($order));
    }

    /**
     * Who made the last change by hand and when; null while the request is untouched.
     */
    private function doneLine(Order $order): ?string
    {
        /** @var OrderStatusHistory|null $last */
        $last = OrderStatusHistory::query()
            ->where('order_id', $order->id)
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->orderByDesc('id')
            ->first();

        if ($last === null || $last->user === null) {
            return null;
        }

        $key = $last->from_status === $last->to_status ? 'extended' : $this->verbKey($last->to_status);

        return __('telegram_staff.done_by.'.$key).' '.$last->user->name.' '.$last->created_at->format('H:i');
    }

    private function verbKey(OrderStatus $status): string
    {
        return trans()->has('telegram_staff.done_by.'.$status->value) ? $status->value : 'default';
    }
}
