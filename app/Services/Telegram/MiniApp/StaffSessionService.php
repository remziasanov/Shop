<?php

declare(strict_types=1);

namespace App\Services\Telegram\MiniApp;

use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Customers\CustomerAuthService;
use App\Services\Telegram\Staff\StaffAccess;
use App\Services\Telegram\TelegramAccountService;
use App\Settings\TelegramSettings;
use App\Support\TelegramMode;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * The staff entry of the Mini App «Рабочее место» (TS-05, D-105). A staff member signs in to the guard
 * `telegram_staff` only: it is not the guard `web`, so the admin panel, exports, the print pages and the Livewire
 * requests of the admin never accept it. Switching between the customer and the staff mode ends the other sign-in and
 * regenerates the session. The Telegram signature is checked with the staff lifetime; the link, the activity of the user,
 * `orders.view` and the feature switch decide the rest. Whenever any condition fails the caller falls back to the customer
 * entry, so the Mini App never tells a stranger whether a Telegram account is linked to staff.
 */
final class StaffSessionService
{
    public const GUARD = 'telegram_staff';

    public function __construct(
        private readonly TelegramInitDataValidator $validator,
        private readonly MiniAppConfig $config,
        private readonly TelegramSettings $settings,
        private readonly StaffAccess $access,
        private readonly TelegramAccountService $accounts,
    ) {}

    /**
     * Opens a staff session when the launch asks for it and every condition holds.
     *
     * @param  ?string  $mode  the unsigned `mode` hint of the web_app button (`/tg?mode=staff`)
     * @return ?string the address to open (relative), null = not a staff entry: the customer entry goes on
     */
    public function tryOpen(Session $session, string $initData, ?string $mode): ?string
    {
        if (! $this->access->workplaceEnabled()) {
            return null;
        }

        try {
            $data = $this->validator->validate($initData, $this->config->botToken(), max(60, $this->settings->initdata_ttl_staff));
        } catch (InvalidInitDataException) {
            return null;
        }

        // The signed start_param says «staff»; the hint only says which mode to try when nothing signed came.
        $start = $data->startParam;
        $intent = $start !== null
            ? ($start === 'staff' || preg_match('/^staff_o_[1-9][0-9]{0,17}$/', $start) === 1)
            : $mode === 'staff';

        if (! $intent) {
            return null;
        }

        $account = TelegramAccount::query()->where('telegram_user_id', $data->userId)->whereNotNull('user_id')->first();
        $user = $account === null ? null : $this->access->linkedUser($account);

        if ($account === null || $user === null || ! $this->access->canEnterWorkplace($user)) {
            return null;
        }

        $this->accounts->touch([
            'id' => $data->userId,
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'username' => $data->username,
            'language_code' => $data->languageCode,
        ], $data->allowsWriteToPm);

        $previous = TelegramMode::telegramUserId($session);

        if ($previous !== null && $previous !== $data->userId) {
            $this->forgetCustomer($session);
            $session->invalidate();
        } else {
            $this->forgetCustomer($session);
            $this->forgetCustomerKeys($session);
            $session->regenerate();
        }

        /** @var SessionGuard $guard */
        $guard = Auth::guard(self::GUARD);
        $guard->login($user);

        $session->put([
            TelegramMode::SESSION_KEY => true,
            TelegramMode::USER_KEY => $data->userId,
            TelegramMode::STAFF_KEY => true,
            TelegramMode::STAFF_AT_KEY => time(),
        ]);

        return $start !== null && preg_match('/^staff_o_(\d+)$/', $start, $m) === 1
            ? route('tg.staff.order', ['order' => (int) $m[1]], false)
            : route('tg.staff.home', [], false);
    }

    /**
     * Ends the staff sign-in of this session (a customer entry, «режим Telegram снят», a failed check). Not `logout()`:
     * it would rotate the remember token of the user and end his or her sessions on other devices.
     */
    public function end(Session $session): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard(self::GUARD);
        $session->forget([$guard->getName(), TelegramMode::STAFF_KEY, TelegramMode::STAFF_AT_KEY]);
        $guard->forgetUser();
    }

    /**
     * Is this session still a valid staff session? Called on every `/tg/staff/*` request and Livewire action (TS-02).
     */
    public function valid(Session $session): ?User
    {
        $user = Auth::guard(self::GUARD)->user();
        $telegramUserId = TelegramMode::telegramUserId($session);
        $at = $session->get(TelegramMode::STAFF_AT_KEY);

        if (! $user instanceof User || ! TelegramMode::staff($session) || $telegramUserId === null || ! is_int($at)) {
            return null;
        }

        // NF-12: eight hours at most, whatever the activity.
        if (time() - $at > (int) config('session.lifetime') * 60) {
            return null;
        }

        if (! $this->access->isLinkedTo($user, $telegramUserId) || ! $this->access->canEnterWorkplace($user)) {
            return null;
        }

        return $user;
    }

    private function forgetCustomer(Session $session): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('customer');
        $session->forget([$guard->getName(), CustomerAuthService::SESSION_VERSION_KEY, TelegramMode::LOGIN_KEY]);
        $guard->forgetUser();
    }

    /**
     * The customer Mini App keys of the session: a staff session is not a customer's (no cart, no account).
     */
    private function forgetCustomerKeys(Session $session): void
    {
        $session->forget([
            TelegramMode::ACCOUNT_KEY,
            TelegramMode::CHAT_KEY,
            TelegramMode::START_KEY,
            TelegramMode::HINT_KEY,
            TelegramMode::CART_MERGE_KEY,
            'channel',
        ]);
    }
}
