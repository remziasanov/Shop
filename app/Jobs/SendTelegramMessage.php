<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\DeliversTelegramMessages;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One message to a private chat (TG-05, TG-10): everything the webhook cannot answer in its response body. The job
 * carries the chat id and the text, never the bot token (NF-61): the client reads it from the settings.
 */
class SendTelegramMessage implements ShouldQueue
{
    use DeliversTelegramMessages;
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $extra  Bot API fields: reply_markup, parse_mode, ...
     */
    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
        public readonly array $extra = [],
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(TelegramClient $client): void
    {
        $this->deliverTelegram(function () use ($client): void {
            $client->sendMessage($this->chatId, $this->text, $this->extra);
        });
    }

    public function failed(Throwable $e): void
    {
        // No chat id and no text: both are personal data (NF-15).
        Log::warning('Telegram message failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
    }
}
