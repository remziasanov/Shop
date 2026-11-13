<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Order;

/**
 * The discount lines of an order's totals (PM-13, PM-18): «Промокод CODE: −X ₽» and the manager's «Скидка». One place for
 * the order page, the cabinet, the e-mails, the print view and the Telegram message, so they cannot drift apart.
 */
final class OrderDiscountLines
{
    /**
     * @return list<array{label: string, value: string}> `value` is already formatted ("−500 ₽", "бесплатная доставка")
     */
    public static function for(Order $order): array
    {
        $lines = [];
        $promo = Money::fromRubles((string) $order->promo_discount);

        if ($order->promo_code !== null && $order->promo_code !== '') {
            $lines[] = [
                'label' => __('promo.order.promo', ['code' => $order->promo_code]),
                'value' => $promo > 0 ? self::minus($promo) : __('promo.order.promo_free_delivery'),
            ];
        }

        $manual = Money::fromRubles((string) $order->manual_discount);

        if ($manual > 0) {
            $lines[] = ['label' => __('promo.order.manual_discount'), 'value' => self::minus($manual)];
        }

        return $lines;
    }

    private static function minus(int $kopecks): string
    {
        return '−'.Money::format($kopecks);
    }
}
