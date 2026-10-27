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
    /** Visible characters of a message (Telegram allows 4096; the margin covers counting differences). */
    public const VISIBLE_LIMIT = 4000;

    /** Longest customer-provided single value (name, phone, item name) in characters. */
    private const VALUE_LIMIT = 200;

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
     * Heading, customer, items, totals and the admin link. The visible text never exceeds {@see self::VISIBLE_LIMIT}:
     * the items list is shortened ("…и ещё N поз."), the rest stays, and ready HTML is never cut.
     */
    protected function compose(Order $order, string $titleKey): string
    {
        $order->loadMissing('items');

        $head = [
            $this->heading($order, $titleKey),
            '',
            $this->e(__('notifications.telegram.customer')).': '.$this->e($this->clip($order->customer_name)),
            $this->e(__('notifications.telegram.phone')).': '.$this->e($this->clip($order->customer_phone)),
        ];

        $tail = [];

        if ($order->total !== null) {
            $tail[] = '';
            $tail[] = $this->e(__('notifications.telegram.total')).': <b>'.$this->e(Money::format(Money::fromRubles($order->total))).'</b>';
        }

        if ($order->type === OrderType::Reservation && $order->reserved_until !== null) {
            $tail[] = $this->e(__('notifications.telegram.reserved_until')).': '.$this->e($order->reserved_until->format('d.m.Y H:i'));
        }

        $tail[] = '';
        $tail[] = '<a href="'.$this->e(self::adminUrl($order)).'">'.$this->e(__('notifications.telegram.open')).'</a>';

        $itemLines = [];

        if ($order->items->isNotEmpty()) {
            $itemLines[] = '';
            $itemLines[] = '<b>'.$this->e(__('notifications.telegram.items')).'</b>';

            $budget = self::VISIBLE_LIMIT - self::visibleLength(implode("\n", [...$head, ...$itemLines, ...$tail])) - 1;
            $lines = $order->items->map(fn (OrderItem $item): string => '• '.$this->e($this->clip($item->name)).' × '.$this->e($item->qty).$this->itemPrice($item))->all();
            $total = array_sum(array_map(fn (string $line): int => self::visibleLength($line) + 1, $lines));
            $shown = 0;

            if ($total <= $budget) {
                $shown = count($lines);
            } else {
                $used = 0;

                foreach ($lines as $index => $line) {
                    $moreLine = $this->e(__('notifications.telegram.more_items', ['count' => count($lines) - $index]));
                    $used += self::visibleLength($line) + 1;

                    if ($used + self::visibleLength($moreLine) + 1 > $budget) {
                        break;
                    }

                    $shown = $index + 1;
                }
            }

            array_push($itemLines, ...array_slice($lines, 0, $shown));

            if ($shown < count($lines)) {
                $itemLines[] = $this->e(__('notifications.telegram.more_items', ['count' => count($lines) - $shown]));
            }
        }

        return implode("\n", [...$head, ...$itemLines, ...$tail]);
    }

    /**
     * Length Telegram counts: the text after tags are removed and entities decoded.
     */
    public static function visibleLength(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Cuts a raw (not yet escaped) value, so no entity or tag is ever split.
     */
    private function clip(?string $value): string
    {
        $value = (string) $value;

        return mb_strlen($value) > self::VALUE_LIMIT ? mb_substr($value, 0, self::VALUE_LIMIT - 1).'…' : $value;
    }

    private function itemPrice(OrderItem $item): string
    {
        if ($item->total === null) {
            return '';
        }

        return ' — '.$this->e(Money::format(Money::fromRubles($item->total)));
    }
}
