<?php

declare(strict_types=1);

namespace App\Services\Notifications\Messages;

use App\Enums\OrderType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Notifications\HtmlEscaper;
use App\Support\Money;

/**
 * Base for Telegram messages about a request (NT-02). Every interpolated value is escaped.
 */
abstract class OrderMessage
{
    abstract public function render(Order $order): string;

    /**
     * Admin URL of the request (absolute, from APP_URL).
     */
    public static function adminUrl(Order $order): string
    {
        return route('filament.admin.resources.orders.view', ['record' => $order->uuid]);
    }

    protected function e(string|int|float|null $value): string
    {
        return HtmlEscaper::e($value);
    }

    protected function heading(Order $order, string $titleKey): string
    {
        return '<b>'.$this->e(__($titleKey, [
            'type' => mb_strtolower($order->type->label()),
            'number' => $order->number,
        ])).'</b>';
    }

    /**
     * @return list<string>
     */
    protected function body(Order $order): array
    {
        $order->loadMissing('items');

        $lines = [
            $this->e(__('notifications.telegram.customer')).': '.$this->e($order->customer_name),
            $this->e(__('notifications.telegram.phone')).': '.$this->e($order->customer_phone),
        ];

        if ($order->items->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>'.$this->e(__('notifications.telegram.items')).'</b>';

            foreach ($order->items as $item) {
                $lines[] = '• '.$this->e($item->name).' × '.$this->e($item->qty).$this->itemPrice($item);
            }
        }

        if ($order->total !== null) {
            $lines[] = '';
            $lines[] = $this->e(__('notifications.telegram.total')).': <b>'.$this->e(Money::format(Money::fromRubles($order->total))).'</b>';
        }

        if ($order->type === OrderType::Reservation && $order->reserved_until !== null) {
            $lines[] = $this->e(__('notifications.telegram.reserved_until')).': '.$this->e($order->reserved_until->format('d.m.Y H:i'));
        }

        $lines[] = '';
        $lines[] = '<a href="'.$this->e(self::adminUrl($order)).'">'.$this->e(__('notifications.telegram.open')).'</a>';

        return $lines;
    }

    private function itemPrice(OrderItem $item): string
    {
        if ($item->total === null) {
            return '';
        }

        return ' — '.$this->e(Money::format(Money::fromRubles($item->total)));
    }
}
