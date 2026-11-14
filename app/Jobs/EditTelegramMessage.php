<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\DeliversTelegramMessages;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The redraw of a bot message that the webhook could not finish inside its short timeout (TG-05, TS-04): the same
 * `editMessageText`, now with the normal timeout and the usual retry policy. Carries ids and the text, never the token.
 */
class EditTelegramMessage implements ShouldQueue
{
    use DeliversTelegramMessages;
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $markup  `reply_markup`
     * @param  ?string  $parseMode  `HTML` for the staff messages (TS-03)
     */
    public function __construct(
        public readonly string $chatId,
        public readonly int $messageId,
        public readonly string $text,
        public readonly array $markup,
        public readonly ?string $parseMode = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TelegramClient $client): void
    {
        $this->deliverTelegram(function () use ($client): void {
            try {
                $client->call('editMessageText', [
                    'chat_id' => $this->chatId,
                    'message_id' => $this->messageId,
                    'text' => $this->text,
                    'reply_markup' => $this->markup,
                ] + ($this->parseMode !== null ? ['parse_mode' => $this->parseMode, 'disable_web_page_preview' => true] : []));
            } catch (TelegramApiException $e) {
                // A timed-out first attempt often was applied: «not modified» / «not found» mean there is nothing to redraw.
                $description = (string) $e->description;

                if ($e->status === 400 && (stripos($description, 'message is not modified') !== false || stripos($description, 'message to edit not found') !== false)) {
                    return;
                }

                throw $e;
            }
        });
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Telegram message edit failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
    }
}
