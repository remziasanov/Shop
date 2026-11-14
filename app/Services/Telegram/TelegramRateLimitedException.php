<?php

declare(strict_types=1);

namespace App\Services\Telegram;

/**
 * A rate limit of ours or Telegram's 429: the queue job is released for `$retryAfter` seconds (TG-10).
 */
class TelegramRateLimitedException extends TelegramApiException
{
    public function __construct(int $retryAfter, ?string $description = null)
    {
        parent::__construct('Telegram rate limit: retry in '.$retryAfter.' s', 429, $description, max($retryAfter, 1));
    }
}
