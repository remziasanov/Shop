<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramRateLimitedException;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Failure policy of the Telegram queue jobs (TG-10, NT-01): a rate limit or HTTP 429 releases the job for the wait
 * time without using up a try; a recipient that cannot be written to (blocked bot, unknown chat) is not retried;
 * every other error is retried (the job class sets `$maxExceptions = 3`). The job needs `retryUntil()` instead of
 * `$tries`, otherwise releases would count as attempts.
 */
trait DeliversTelegramMessages
{
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * @param  Closure(): void  $send
     */
    protected function deliverTelegram(Closure $send): void
    {
        try {
            $send();
        } catch (TelegramRateLimitedException $e) {
            $this->release($e->retryAfter ?? 5);
        } catch (TelegramApiException $e) {
            if (! $e->isPermanent()) {
                throw $e;
            }

            // Final: the work chat error / blocked flag is recorded by the client; the log carries no personal data.
            Log::warning('Telegram message not delivered', ['job' => static::class, 'status' => $e->status]);
        }
    }
}
