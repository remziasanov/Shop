<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Account\RegisterController;
use App\Models\Order;
use App\Services\Access\ViewerContext;
use App\Services\Telegram\TelegramLinkService;
use App\Settings\CustomerSettings;
use App\Settings\SalesSettings;
use App\Support\TelegramMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class OrderThanksController extends Controller
{
    /**
     * SF-47, SF-52: the order is addressed by its unguessable UUID only; the page is not indexed.
     */
    public function __invoke(string $uuid, SalesSettings $settings, CustomerSettings $customers, ViewerContext $viewer): View
    {
        abort_unless(Str::isUuid($uuid), 404);

        $order = Order::query()->where('uuid', $uuid)->with('items')->firstOrFail();

        $text = match ($order->type->value) {
            'reservation' => $settings->thanks_reservation_text,
            'inquiry' => $settings->thanks_inquiry_text,
            default => $settings->thanks_order_text,
        };

        // CA-27: a guest who left an e-mail can create an account with it (registration must be on).
        $offerAccount = $customers->registration_enabled
            && $viewer->customer() === null
            && $order->customer_id === null
            && filled($order->customer_email);

        if ($offerAccount) {
            // The registration form is prefilled from this order, for this session only: the address never travels in a URL
            // (it would end up in server logs and Referer headers, NF-15).
            request()->session()->put(RegisterController::THANKS_ORDER_KEY, $order->uuid);
        }

        // MR-11: the other request of the same checkout (the order and the reservation point at each other), order first.
        $related = $order->related_order_id !== null ? Order::query()->whereKey($order->related_order_id)->with('items')->first() : null;
        $orders = $related === null ? [$order] : collect([$order, $related])->sortBy(fn (Order $request): int => $request->type === OrderType::Order ? 0 : 1)->values()->all();

        // TG-08: «Получать статус в Telegram» for all requests of this checkout, only while the bot is connected and
        // customer notifications are on; not inside the Mini App, where the requests are bound to the chat already.
        $telegramLink = TelegramMode::active() || $orders[0]->telegram_account_id !== null
            ? null
            : app(TelegramLinkService::class)->orderLink($orders, request()->session());

        return view('storefront.order-thanks', [
            'telegramLink' => $telegramLink,
            'order' => $order,
            'orders' => $orders,
            'offerAccount' => $offerAccount,
            'thanksText' => $text !== null && trim($text) !== '' ? $text : __('storefront_sales.thanks.default_'.$order->type->value),
        ]);
    }
}
