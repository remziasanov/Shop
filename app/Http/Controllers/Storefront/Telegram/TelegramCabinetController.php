<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramAccount;
use App\Services\Seo\SeoMeta;
use App\Settings\CustomerSettings;
use App\Support\TelegramMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The Mini App cabinet of a visitor without an account and the «О магазине» page (TM-04, TM-06): sign in with the code
 * from the e-mail, register, shop details, the site in the browser. Both pages exist only in the Telegram mode.
 */
class TelegramCabinetController extends Controller
{
    /**
     * TM-04 (б): a guest sees «Войти в аккаунт сайта» and «Зарегистрироваться»; a customer goes to the cabinet.
     */
    public function me(CustomerSettings $customers): View|RedirectResponse
    {
        if (! TelegramMode::active()) {
            return redirect()->route('home');
        }

        if (auth('customer')->check()) {
            return redirect()->route('account');
        }

        return view('storefront.telegram.me', [
            'registrationEnabled' => $customers->registration_enabled,
            'telegram' => TelegramMode::accountId() !== null ? TelegramAccount::query()->find(TelegramMode::accountId(), ['id', 'first_name', 'last_name', 'username']) : null,
            'seo' => new SeoMeta(title: __('telegram_miniapp.me.title'), robots: 'noindex,nofollow'),
        ]);
    }

    /**
     * TM-06: legal pages, contacts and the seller's details (SF-72) of the site footer.
     */
    public function about(): View|RedirectResponse
    {
        if (! TelegramMode::active()) {
            return redirect()->route('home');
        }

        return view('storefront.telegram.about', [
            'seo' => new SeoMeta(title: __('telegram_miniapp.about.title'), robots: 'noindex,nofollow'),
        ]);
    }
}
