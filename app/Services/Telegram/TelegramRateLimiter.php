<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Outgoing message limits (TG-10), on the file cache: 25 messages per second in total, 1 per second to one chat and
 * 20 per minute to the work chat. {@see self::acquire()} either takes a slot in every applicable bucket or none.
 */
class TelegramRateLimiter
{
    public const GLOBAL_PER_SECOND = 25;

    public const CHAT_PER_SECOND = 1;

    public const WORK_CHAT_PER_MINUTE = 20;

    /**
     * @return int 0 when the message may be sent now (the slots are taken), else the seconds to wait
     */
    public function acquire(string $chatId, bool $workChat = false): int
    {
        if (! config('shop.telegram_rate_limits')) {
            return 0;
        }

        $chatKey = 'telegram:chat:'.md5($chatId);
        $buckets = [
            ['telegram:global', self::GLOBAL_PER_SECOND, 1],
            [$chatKey, self::CHAT_PER_SECOND, 1],
        ];

        if ($workChat) {
            $buckets[] = ['telegram:work-chat', self::WORK_CHAT_PER_MINUTE, 60];
        }

        $wait = 0;

        foreach ($buckets as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        if ($wait > 0) {
            return $wait;
        }

        foreach ($buckets as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return 0;
    }
}
