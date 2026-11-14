<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TelegramMessageStatus;
use App\Jobs\Concerns\DeliversTelegramMessages;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramRateLimitedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivery of one customer message (TN-04). The `telegram_messages` row exists before the job runs; only a `queued`
 * row is sent, so a repeated job (a retry after the answer was lost, a duplicate dispatch) never sends twice. The state
 * of the recipient is checked again at send time (the customer may have pressed «Не присылать уведомления» meanwhile).
 * The job carries the row id and the text, never the bot token (NF-61).
 */
class SendCustomerTelegramMessage implements ShouldQueue
{
    use DeliversTelegramMessages;
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $extra  Bot API fields: reply_markup, ...
     */
    public function __construct(
        public readonly int $messageId,
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
        $row = TelegramMessage::query()->with('account')->find($this->messageId);

        if ($row === null || $row->status !== TelegramMessageStatus::Queued) {
            return;
        }

        $account = $row->account;

        if ($account === null || ! $account->canMessage()) {
            $row->forceFill(['status' => TelegramMessageStatus::Skipped, 'error' => 'recipient_unavailable'])->save();

            return;
        }

        $this->deliverTelegram(function () use ($client, $row, $account): void {
            try {
                $result = $client->sendMessage((string) $account->telegram_user_id, $this->text, $this->extra);
            } catch (TelegramApiException $e) {
                // A blocked bot / unknown chat is final: the client already set `bot_blocked_at`, nothing is retried.
                if (! $e instanceof TelegramRateLimitedException && $e->isPermanent()) {
                    $this->markFailed($row, $e);
                }

                throw $e;
            }

            $messageId = $result['message_id'] ?? null;

            $row->forceFill([
                'status' => TelegramMessageStatus::Sent,
                'message_id' => is_numeric($messageId) ? (int) $messageId : null,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        });
    }

    /** The last attempt failed: the status change that caused the message is long committed (NT-01). */
    public function failed(Throwable $e): void
    {
        $row = TelegramMessage::query()->find($this->messageId);

        if ($row !== null && $row->status === TelegramMessageStatus::Queued) {
            $this->markFailed($row, $e);
        }

        Log::warning('Customer Telegram message failed', ['exception' => $e::class, 'message_id' => $this->messageId]);
    }

    private function markFailed(TelegramMessage $row, Throwable $e): void
    {
        // The text of a Telegram error is the Bot API description (token already removed by the client); anything else
        // is reduced to its class, so no token or personal data reaches the log table.
        $error = $e instanceof TelegramApiException ? $e->getMessage() : $e::class;

        $row->forceFill(['status' => TelegramMessageStatus::Failed, 'error' => mb_substr($error, 0, 250)])->save();
    }
}
