<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Подключить Telegram» in the cabinet notifications section (TG-07): a POST (CSRF-protected) issues a one-time
 * `c_` link and sends the customer into the bot, where linking is confirmed with a button. «Отключить» releases the Telegram.
 */
class TelegramLinkController extends Controller
{
    use RendersAccountPages;

    public function connect(Request $request, TelegramLinkService $links): RedirectResponse
    {
        $link = $links->customerLink($this->currentCustomer($request));

        if ($link === null) {
            return redirect()->route('customer.notifications')->withErrors(['telegram' => __('telegram.cabinet.link_unavailable')]);
        }

        return redirect()->away($link);
    }

    public function disconnect(Request $request, TelegramLinkService $links): RedirectResponse
    {
        $links->unlinkCustomer($this->currentCustomer($request));

        return redirect()->route('customer.notifications')->with('status', __('telegram.cabinet.disconnected'));
    }
}
