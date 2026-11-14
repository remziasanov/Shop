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
        $this->disable($account);

        return $this->messages->reply($account, __('telegram.bot.stopped'));
    }

    /**
     * The «Не присылать уведомления» button under a customer message (TN-06): the same switch as `/stop`.
     */
    public function disable(TelegramAccount $account): void
    {
        if ($account->notify_enabled) {
            $account->notify_enabled = false;
            $account->save();
        }
    }
}
