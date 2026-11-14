<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Notifications\TelegramException;

/**
 * A failed Bot API call (TG-10). The message never contains the bot token.
 */
class TelegramApiException extends TelegramException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $description = null,
        public readonly ?int $retryAfter = null,
        public readonly ?int $migrateToChatId = null,
    ) {
        parent::__construct($message);
    }

    /** 403: the user blocked the bot, the bot was removed from the chat, the user is deactivated. */
    public function isForbidden(): bool
    {
        return $this->status === 403;
    }

    public function isChatNotFound(): bool
    {
        return $this->status === 400 && stripos((string) $this->description, 'chat not found') !== false;
    }

    /** No retry helps: the recipient cannot be written to (TG-10). */
    public function isPermanent(): bool
    {
        return $this->isForbidden() || $this->isChatNotFound();
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
