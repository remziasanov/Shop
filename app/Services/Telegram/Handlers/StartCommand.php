<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramAccount;
use App\Services\Telegram\Staff\StaffAccess;
use App\Services\Telegram\TelegramLinkService;
use App\Services\Telegram\TelegramMessages;

/**
 * `/start` (TG-05, TG-07): welcome with «Открыть магазин», or a one-time link parameter. Re-enables notifications.
 */
class StartCommand
{
    public function __construct(
        private readonly TelegramMessages $messages,
        private readonly TelegramLinkService $links,
        private readonly StaffAccess $staff,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(TelegramAccount $account, string $param): array
    {
        if (! $account->notify_enabled) {
            $account->notify_enabled = true;
            $account->save();
        }

        // TS-01: `t.me/{bot}?start=staff` from the work chat message; web_app buttons work only in a private chat.
        if ($param === 'staff') {
            return $this->workplace($account);
        }

        if ($param !== '') {
            $reply = $this->links->handleStart($account, $param);

            if ($reply !== null) {
                return $reply;
            }
        }

        return $this->messages->reply($account, $this->messages->welcomeText(), $this->messages->openShopMarkup());
    }

    /**
     * @return array<string, mixed>
     */
    private function workplace(TelegramAccount $account): array
    {
        $user = $this->staff->linkedUser($account);

        if ($user === null) {
            return $this->messages->reply($account, __('telegram_staff.bot.not_linked'));
        }

        if (! $this->staff->canEnterWorkplace($user)) {
            return $this->messages->reply($account, __('telegram_staff.bot.unavailable'));
        }

        return $this->messages->reply($account, __('telegram_staff.bot.workplace_text'), ['inline_keyboard' => [[$this->messages->workplaceButton()]]]);
    }
}
