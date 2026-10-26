<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class OrderThanksController extends Controller
{
    /**
     * SF-47, SF-52: the order is addressed by its unguessable UUID only; the page is not indexed.
     */
    public function __invoke(string $uuid, SalesSettings $settings): View
    {
        abort_unless(Str::isUuid($uuid), 404);

        $order = Order::query()->where('uuid', $uuid)->with('items')->firstOrFail();

        $text = match ($order->type->value) {
            'reservation' => $settings->thanks_reservation_text,
            'inquiry' => $settings->thanks_inquiry_text,
            default => $settings->thanks_order_text,
        };

        return view('storefront.order-thanks', [
            'order' => $order,
            'thanksText' => $text !== null && trim($text) !== '' ? $text : __('storefront_sales.thanks.default_'.$order->type->value),
        ]);
    }
}
