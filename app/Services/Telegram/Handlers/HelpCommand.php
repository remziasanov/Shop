<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramMessages;

/**
 * `/help` (TG-05).
 */
class HelpCommand
{
    public function __construct(private readonly TelegramMessages $messages) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(TelegramAccount $account): array
    {
        return $this->messages->reply($account, $this->messages->helpText(), $this->messages->openShopMarkup());
    }
}
