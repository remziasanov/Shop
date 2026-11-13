<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderChangeKind;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderStatusHistory;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * «История изменений» of the order card (OE-07): the rows of the change log grouped by saved batch and user, merged by
 * time with the status history. Newest first. Everything is already formatted for display.
 */
final class OrderTimeline
{
    private const MONEY_FIELDS = ['delivery_price', 'manual_discount', 'promo_discount', 'items_total', 'total', 'price'];

    /**
     * @return list<array{type: 'status'|'batch', at: CarbonInterface, user: string, text: string, comment: string|null, rows: list<array{title: string, old: string|null, new: string|null}>}>
     */
    public function for(Order $order): array
    {
        $entries = [];

        $order->loadMissing('statusHistory.user');

        foreach ($order->statusHistory as $history) {
            $entries[] = [
                'type' => 'status',
                'at' => $history->created_at,
                'sort' => [$history->created_at->getTimestamp(), 0, $history->id],
                'user' => $history->user->name ?? __('admin_orders.view.actor_system'),
                'text' => $this->statusText($history, $order),
                'comment' => $history->comment,
                'rows' => [],
            ];
        }

        $batches = [];

        foreach ($order->editLog()->with('user')->get() as $change) {
            $batches[$change->batch_uuid][] = $change;
        }

        foreach ($batches as $changes) {
            $first = $changes[0];
            $entries[] = [
                'type' => 'batch',
                'at' => $first->created_at,
                'sort' => [$first->created_at->getTimestamp(), 1, $first->id],
                'user' => $first->user->name ?? __('admin_orders.view.actor_system'),
                'text' => __('order_edit.timeline.batch'),
                'comment' => null,
                'rows' => array_map(fn (OrderChange $change): array => $this->row($change), $changes),
            ];
        }

        usort($entries, fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        return array_map(function (array $entry): array {
            unset($entry['sort']);

            return $entry;
        }, $entries);
    }

    private function statusText(OrderStatusHistory $history, Order $order): string
    {
        $to = $history->to_status->label($order->type);

        return $history->from_status === null
            ? $to.' ('.__('admin_orders.view.created').')'
            : $history->from_status->label($order->type).' → '.$to;
    }

    /**
     * @return array{title: string, old: string|null, new: string|null}
     */
    private function row(OrderChange $change): array
    {
        $title = match ($change->kind) {
            OrderChangeKind::ItemAdded, OrderChangeKind::ItemRemoved => $change->kind->label().': '.$change->item_label,
            OrderChangeKind::ItemQty, OrderChangeKind::ItemPrice => $change->kind->label().': '.$change->item_label,
            OrderChangeKind::Stock => $change->kind->label().': '.$change->item_label,
            default => __('order_edit.fields.'.$change->field),
        };

        if ($change->kind === OrderChangeKind::Stock) {
            $delta = (int) $change->new_value;
            $unit = $change->field === 'reserved_qty' ? __('order_edit.timeline.reserve') : __('order_edit.timeline.warehouse');

            return ['title' => $title, 'old' => null, 'new' => ($delta > 0 ? '+' : '−').abs($delta).' ('.$unit.')'];
        }

        return [
            'title' => $title,
            'old' => $this->value($change, $change->old_value),
            'new' => $this->value($change, $change->new_value),
        ];
    }

    private function value(OrderChange $change, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($change->kind === OrderChangeKind::ItemAdded || $change->kind === OrderChangeKind::ItemRemoved) {
            [$qty, $price] = array_pad(explode(' × ', $value, 2), 2, '0');

            return $qty.' × '.Money::format(Money::fromRubles($price));
        }

        if ($change->kind === OrderChangeKind::ItemPrice || in_array($change->field, self::MONEY_FIELDS, true)) {
            return Money::format(Money::fromRubles($value));
        }

        return $value;
    }
}
