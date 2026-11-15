<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramBotSetup;
use App\Services\Users\PermissionService;
use App\Settings\TelegramSettings;

/**
 * Who is a staff member in Telegram (TS-02, TS-03, TS-05): the user linked to a Telegram account by the confirmed
 * `s_` link. Nothing is cached: every call reads the link, the activity flag and the role, so a deactivation, a removed
 * role or an unlinked Telegram take effect on the next tap or request.
 */
final class StaffAccess
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TelegramSettings $settings,
        private readonly TelegramBotSetup $setup,
    ) {}

    /**
     * The active staff member linked to this Telegram user id; null when there is none.
     */
    public function userByTelegramId(int $telegramUserId): ?User
    {
        $account = TelegramAccount::query()->where('telegram_user_id', $telegramUserId)->whereNotNull('user_id')->first(['id', 'user_id']);

        return $account === null ? null : $this->linkedUser($account);
    }

    /**
     * The linked user of an account when he or she is active.
     */
    public function linkedUser(TelegramAccount $account): ?User
    {
        if ($account->user_id === null) {
            return null;
        }

        $user = User::query()->find($account->user_id);

        return $user !== null && $user->is_active ? $user : null;
    }

    /**
     * Is the user (still) linked to exactly this Telegram user id?
     */
    public function isLinkedTo(User $user, int $telegramUserId): bool
    {
        return TelegramAccount::query()->where('telegram_user_id', $telegramUserId)->where('user_id', $user->id)->exists();
    }

    /**
     * The bot is connected (token, identity, «Подключить бота» done, the same check as the customer notifications;
     * NF-63). The work chat switch (`enabled`) is not a master switch: it concerns the work chat messages and buttons only.
     */
    public function botReady(): bool
    {
        return $this->setup->isConnected();
    }

    public function workplaceEnabled(): bool
    {
        return $this->settings->staff_workplace_enabled && $this->settings->miniapp_enabled && $this->botReady();
    }

    /**
     * TD-29: the buttons of the work chat messages need the work chat switch (`enabled`); the personal copies (TS-08) do
     * not depend on it, they need the buttons setting and the connected bot only.
     */
    public function buttonsEnabled(bool $forWorkChat = true): bool
    {
        if ($forWorkChat && ! $this->settings->enabled) {
            return false;
        }

        return $this->settings->work_chat_buttons_enabled && $this->botReady();
    }

    /** TS-05: all entry conditions of the Mini App except the signature: feature on, active user, `orders.view`. */
    public function canEnterWorkplace(User $user): bool
    {
        return $this->workplaceEnabled() && $user->is_active && $this->permissions->allows($user, 'orders.view');
    }
}
