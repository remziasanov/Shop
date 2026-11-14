<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramAccount;
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

        if ($param !== '') {
            $reply = $this->links->handleStart($account, $param);

            if ($reply !== null) {
                return $reply;
            }
        }

        return $this->messages->reply($account, $this->messages->welcomeText(), $this->messages->openShopMarkup());
    }
}
