<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramMessages;

/**
 * `/stop` (TG-05, TN-06): turns the customer notifications off; `/start` turns them on again.
 */
class StopCommand
{
    public function __construct(private readonly TelegramMessages $messages) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(TelegramAccount $account): array
    {
        $account->notify_enabled = false;
        $account->save();

        return $this->messages->reply($account, __('telegram.bot.stopped'));
    }
}
