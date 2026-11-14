<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\Telegram\MiniApp\InvalidInitDataException;
use App\Services\Telegram\MiniApp\MiniAppConfig;
use App\Services\Telegram\MiniApp\TelegramSessionService;
use App\Services\Telegram\MiniApp\TelegramStartParamResolver;
use App\Settings\GeneralSettings;
use App\Support\TelegramMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The entry of the customer Mini App (TM-01…TM-03, TM-05, TM-08): `GET /tg` is the address of the bot's menu button and
 * of every `web_app` button; the page loads the official Telegram script, sends `initData` to `POST /tg/session` and
 * leaves through `location.replace`. The page itself runs no catalog SQL.
 */
class TelegramEntryController extends Controller
{
    /**
     * TM-01: the light entry page. Outside Telegram the script finds no `initData` and shows «Это приложение открывается
     * в Telegram»; with the Mini App off the page says so at once.
     */
    public function show(MiniAppConfig $config, GeneralSettings $general): View
    {
        return view('storefront.telegram.entry', [
            'enabled' => $config->enabled(),
            'botUrl' => $config->botUrl(),
            'siteName' => $general->shop_name,
            'siteUrl' => route('home'),
        ]);
    }

    /**
     * TM-02, TM-03: verifies `initData` (the only thing the server trusts), opens the session and tells the page where
     * to go: the home page, or `/tg/start` when the launch carried a `start_param` or a `?to=` hint.
     */
    public function session(Request $request, MiniAppConfig $config, TelegramSessionService $sessions): JsonResponse
    {
        if (! $config->enabled()) {
            return response()->json(['error' => 'disabled', 'message' => __('telegram_miniapp.entry.disabled')], 403);
        }

        $initData = $request->input('initData');
        $hint = $request->input('to');

        try {
            $sessions->open($request->session(), is_string($initData) ? $initData : '', is_string($hint) ? $hint : null);
        } catch (InvalidInitDataException $e) {
            // Only the reason is logged: initData holds the personal data of the user (NF-15).
            Log::info('Telegram initData rejected', ['reason' => $e->reason]);

            return response()->json(['error' => 'forbidden', 'message' => __('telegram_miniapp.entry.forbidden')], 403);
        }

        $session = $request->session();
        $launch = $session->has(TelegramMode::START_KEY) || $session->has(TelegramMode::HINT_KEY) || $session->has(TelegramMode::CART_MERGE_KEY);

        return response()->json(['url' => $launch ? route('tg.start', [], false) : '/']);
    }

    /**
     * TM-03: the page was opened outside Telegram (for instance a cookie shared by the in-app browser of iOS): the mode
     * is removed and the client reloads the page in its usual form.
     */
    public function leave(Request $request, TelegramSessionService $sessions): JsonResponse
    {
        $sessions->leave($request->session());

        return response()->json(['ok' => true]);
    }

    /**
     * TM-08: opens the page of the launch parameter, once. The signed `start_param` wins over the unsigned hint.
     */
    public function start(Request $request, TelegramStartParamResolver $resolver, TelegramSessionService $sessions): RedirectResponse
    {
        if (! TelegramMode::active()) {
            return redirect()->route('home');
        }

        $session = $request->session();
        // TM-13: the guest cart joins the customer's cart here, not in POST /tg/session.
        $sessions->mergePendingCart($session);

        $signed = $session->pull(TelegramMode::START_KEY);
        $hint = $session->pull(TelegramMode::HINT_KEY);
        $param = is_string($signed) ? $signed : (is_string($hint) ? $hint : null);

        // Only the signed parameter may carry `check` and `staff*` (TG-03): the hint is client-controlled.
        return $resolver->resolve($param, $request, signed: is_string($signed));
    }

    /**
     * TM-05: the result of `WebApp.requestWriteAccess()`. A granted access means the bot may write; the account row is
     * updated so the status messages can reach the chat. The value is a client claim: if it is wrong, the first
     * message fails and the bot marks the chat as blocked (TN).
     */
    public function writeAccess(Request $request): JsonResponse
    {
        $accountId = TelegramMode::accountId();

        if ($accountId === null) {
            return response()->json(['ok' => false], 403);
        }

        if ($request->boolean('allowed')) {
            TelegramAccount::query()->whereKey($accountId)->whereNull('chat_started_at')->update(['chat_started_at' => now()]);
            $request->session()->put(TelegramMode::CHAT_KEY, true);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * TG-09, TM-05: the phone the user shared with the bot (verified by Telegram), for «Подставить мой номер».
     */
    public function phone(): JsonResponse
    {
        $accountId = TelegramMode::accountId();
        $phone = $accountId === null ? null : TelegramAccount::query()->whereKey($accountId)->whereNotNull('phone_verified_at')->value('phone');

        return response()->json(['phone' => is_string($phone) ? $phone : null]);
    }
}
