<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\TelegramAccount;
use App\Services\Cart\CartMerger;
use App\Services\Customers\CustomerAuthService;
use App\Services\Telegram\TelegramAccountService;
use App\Services\Telegram\TelegramLinkService;
use App\Support\TelegramMode;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Creates, keeps and ends the Telegram Mini App session (TM-02…TM-04, D-101, D-102).
 *
 * The mode lives in the session only. A second Telegram user in the same session resets everything (the browser
 * of a shared device must not carry the cart or the sign-in of the previous user). A linked customer is signed in
 * automatically while the stored `auth_version` still matches (TM-04): the sign-in is flagged «через Telegram» and
 * exists only in the Telegram mode.
 */
final class TelegramSessionService
{
    public function __construct(
        private readonly TelegramInitDataValidator $validator,
        private readonly MiniAppConfig $config,
        private readonly TelegramLinkService $links,
        private readonly CartMerger $merger,
        private readonly TelegramAccountService $accounts,
    ) {}

    /**
     * Verifies initData and opens the session. At most 5 SQL statements for a guest and a linked customer (TM-13).
     *
     * @throws InvalidInitDataException
     */
    public function open(Session $session, string $initData, ?string $hint): TelegramAccount
    {
        $data = $this->validator->validate($initData, $this->config->botToken(), $this->config->initDataTtl());
        $account = $this->touchAccount($data);

        $previous = TelegramMode::telegramUserId($session);

        if ($previous !== null && $previous !== $data->userId) {
            $this->reset($session);
        } else {
            $session->regenerate();
        }

        $session->put([
            TelegramMode::SESSION_KEY => true,
            TelegramMode::USER_KEY => $data->userId,
            TelegramMode::ACCOUNT_KEY => $account->id,
            TelegramMode::CHAT_KEY => $account->chat_started_at !== null,
            'channel' => 'telegram',
        ]);

        // The signed start_param wins; the `to` hint of a web_app button is only remembered when nothing signed came.
        $session->forget([TelegramMode::START_KEY, TelegramMode::HINT_KEY]);

        if ($data->startParam !== null) {
            $session->put(TelegramMode::START_KEY, $data->startParam);
        } elseif ($hint !== null && $hint !== '') {
            $session->put(TelegramMode::HINT_KEY, mb_substr($hint, 0, 300));
        }

        $this->autoLogin($session, $account);

        return $account;
    }

    /**
     * TM-04 (б), (в): the customer proved the account (the code of the e-mail, the confirmed registration): the Telegram
     * of this Mini App session is linked to it, moving it from another customer if needed and without an e-mail (the
     * customer has just proved the ownership with an e-mail code). Nothing happens outside the Telegram mode.
     */
    public function linkSessionAccount(Session $session, Customer $customer): void
    {
        $accountId = TelegramMode::accountId($session);
        $account = $accountId !== null ? TelegramAccount::query()->find($accountId) : null;

        if ($account !== null && ! $this->links->bindCustomer($account, $customer, null, replaceExisting: true, notifyByEmail: false)) {
            // A concurrent link won or the account vanished: the customer stays signed in, only the Telegram is not linked.
            $session->flash(TelegramStartParamResolver::NOTICE_KEY, __('telegram_miniapp.link_failed'));
        }
    }

    /**
     * TM-04 (г): a session signed in through Telegram stays valid only while this Telegram is still linked to the same
     * customer with the same `auth_version` and the auto-login is on. One indexed query, asked on every request of such a
     * session; the cabinet button «Отключить Telegram» and the admin switch therefore end the sign-in at once.
     */
    public function loginStillValid(Session $session, Customer $customer): bool
    {
        $accountId = TelegramMode::accountId($session);

        if ($accountId === null || ! $this->config->autologinEnabled()) {
            return false;
        }

        return TelegramAccount::query()
            ->whereKey($accountId)
            ->where('customer_id', $customer->id)
            ->where('customer_auth_version', $customer->auth_version)
            ->exists();
    }

    /**
     * Drops the customer of a Telegram sign-in from this session (the Telegram guest session stays).
     */
    public function endTelegramLogin(Session $session): void
    {
        $this->forgetCustomer($session);
        $session->forget(TelegramMode::LOGIN_KEY);
    }

    /**
     * CA-15, TM-10: the guest cart of this Telegram joins the cart of the customer who was signed in on entry. Deferred
     * from POST /tg/session (TM-13: at most 5 SQL there) to the first page request (`/tg/start`).
     */
    public function mergePendingCart(Session $session): void
    {
        if ($session->pull(TelegramMode::CART_MERGE_KEY) !== true) {
            return;
        }

        $accountId = TelegramMode::accountId($session);
        $customer = Auth::guard('customer')->user();

        if ($accountId === null || ! $customer instanceof Customer) {
            return;
        }

        $guest = Cart::query()->where('telegram_account_id', $accountId)->whereNull('customer_id')->value('token');

        if (is_string($guest)) {
            $this->merger->merge($customer, $guest);
        }
    }

    /**
     * «Режим Telegram снят» (POST /tg/leave): the page was opened outside Telegram. The signed-in customer of a
     * Telegram sign-in leaves with it; a sign-in made by password stays.
     */
    public function leave(Session $session): void
    {
        if ($session->get(TelegramMode::LOGIN_KEY) === true) {
            $this->forgetCustomer($session);
        }

        $session->forget([
            TelegramMode::SESSION_KEY,
            TelegramMode::USER_KEY,
            TelegramMode::ACCOUNT_KEY,
            TelegramMode::LOGIN_KEY,
            TelegramMode::CHAT_KEY,
            TelegramMode::START_KEY,
            TelegramMode::HINT_KEY,
            TelegramMode::CART_MERGE_KEY,
            'channel',
        ]);
        $session->regenerate();
    }

    /**
     * The account row of the Mini App user: created or refreshed (names, username, language, last seen); the chat is
     * marked as started when the user allowed the bot to write (TM-03).
     */
    public function touchAccount(InitData $data): TelegramAccount
    {
        return $this->accounts->touch([
            'id' => $data->userId,
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'username' => $data->username,
            'language_code' => $data->languageCode,
        ], $data->allowsWriteToPm);
    }

    /**
     * TM-04 (а): a linked, usable customer with an unchanged `auth_version` is signed in; otherwise the link ends
     * (CA-31: password reset, e-mail change, «завершить сессии» or a block). A sign-in made by an earlier Telegram entry
     * of this session ends as soon as these conditions no longer hold (TM-04 г: unlinked, relinked, auto-login off).
     */
    private function autoLogin(Session $session, TelegramAccount $account): void
    {
        $viaTelegram = $session->get(TelegramMode::LOGIN_KEY) === true;

        if ($account->customer_id === null || ! $this->config->autologinEnabled()) {
            if ($viaTelegram) {
                $this->endTelegramLogin($session);
            }

            return;
        }

        $customer = Customer::query()->find($account->customer_id);

        if ($customer === null || ! $customer->canSignIn() || $account->customer_auth_version !== $customer->auth_version) {
            $this->links->unlinkAccount($account);

            if ($viaTelegram) {
                $this->endTelegramLogin($session);
            }

            return;
        }

        $guard = Auth::guard('customer');
        $guard->login($customer);
        $session->put(CustomerAuthService::SESSION_VERSION_KEY, $customer->auth_version);
        $session->put(TelegramMode::LOGIN_KEY, true);

        // CA-15, TM-10: a guest cart of this Telegram joins the cart of the customer; the merge itself waits for the
        // first page request (one cheap lookup here, TM-13).
        if (Cart::query()->where('telegram_account_id', $account->id)->whereNull('customer_id')->exists()) {
            $session->put(TelegramMode::CART_MERGE_KEY, true);
        }
    }

    /**
     * Another Telegram user: the session is emptied completely, including the customer sign-in and the guest keys.
     */
    private function reset(Session $session): void
    {
        $this->forgetCustomer($session);
        $session->invalidate();
    }

    /**
     * Drops the customer from this session only. No logout(): that would rotate the remember token and end the
     * customer's valid sessions on other devices.
     */
    private function forgetCustomer(Session $session): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('customer');
        $session->forget([$guard->getName(), CustomerAuthService::SESSION_VERSION_KEY]);
        $guard->forgetUser();
    }
}
