<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramAccount;
use App\Settings\TelegramSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Telegram Bot API over the Laravel HTTP client (TG-10, D-100). The token is read from the encrypted settings at
 * call time (never stored in queued jobs) and is stripped from every error text (NF-61).
 */
class TelegramClient
{
    public const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramRateLimiter $limiter,
    ) {}

    public function hasToken(): bool
    {
        return filled($this->settings->bot_token);
    }

    /**
     * One Bot API call.
     *
     * @param  array<string, mixed>  $params
     * @param  ?string  $token  override of the saved token (token check before saving, test button)
     * @return array<mixed> the `result` of the response
     *
     * @throws TelegramApiException
     * @throws TelegramRateLimitedException on HTTP 429
     */
    public function call(string $method, array $params = [], ?string $token = null): array
    {
        $token = (string) ($token ?: $this->settings->bot_token);

        if ($token === '') {
            throw new TelegramApiException(__('notifications.telegram_not_configured'));
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, $params);
        } catch (ConnectionException $e) { // not chained: the original message may contain the URL with the token
            throw new TelegramApiException($this->redact($e->getMessage(), $token));
        }

        if ($response->failed() || $response->json('ok') !== true) {
            $description = $this->redact((string) ($response->json('description') ?? ''), $token);
            $status = (int) ($response->json('error_code') ?? $response->status());
            $retryAfter = $response->json('parameters.retry_after');
            $migrate = $response->json('parameters.migrate_to_chat_id');

            if ($status === 429) {
                throw new TelegramRateLimitedException(is_numeric($retryAfter) ? (int) $retryAfter : 5, $description);
            }

            throw new TelegramApiException(
                'Telegram API error (HTTP '.$status.')'.($description !== '' ? ': '.$description : ''),
                $status,
                $description,
                null,
                is_numeric($migrate) ? (int) $migrate : null,
            );
        }

        $result = $response->json('result');

        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * A message to a private chat. Honours the rate limits (throws {@see TelegramRateLimitedException} so the job can
     * be released); a blocked bot / unknown chat marks the account and is not retried (check `isPermanent()`).
     *
     * @param  array<string, mixed>  $extra  Bot API fields: reply_markup, parse_mode, ...
     * @return array<mixed>
     */
    public function sendMessage(string $chatId, string $text, array $extra = []): array
    {
        $wait = $this->limiter->acquire($chatId);

        if ($wait > 0) {
            throw new TelegramRateLimitedException($wait);
        }

        try {
            return $this->call('sendMessage', $this->messageParams($chatId, $text, $extra));
        } catch (TelegramApiException $e) {
            if ($e->isPermanent() && ctype_digit(ltrim($chatId, '-'))) {
                TelegramAccount::query()
                    ->where('telegram_user_id', (int) $chatId)
                    ->whereNull('bot_blocked_at')
                    ->update(['bot_blocked_at' => now()]);
            }

            throw $e;
        }
    }

    /**
     * A message to the shop work chat (NT-02). With `$token`/`$chatId` overrides (the unsaved-values test button)
     * no limits apply and the work chat state is left alone.
     *
     * @param  array<string, mixed>  $extra
     *
     * @throws TelegramApiException
     */
    public function sendToWorkChat(string $html, array $extra = [], ?string $token = null, ?string $chatId = null): void
    {
        $override = filled($token) || filled($chatId);
        $chatId = (string) ($chatId ?: $this->settings->work_chat_id);

        if ($chatId === '') {
            throw new TelegramApiException(__('notifications.telegram_not_configured'));
        }

        if (! $override) {
            $wait = $this->limiter->acquire($chatId, true);

            if ($wait > 0) {
                throw new TelegramRateLimitedException($wait);
            }
        }

        try {
            try {
                $this->call('sendMessage', $this->messageParams($chatId, $html, $extra), $token);
            } catch (TelegramApiException $e) {
                // TG-10: the group became a supergroup: switch to the new id and send again (once).
                if ($e->migrateToChatId === null || $override) {
                    throw $e;
                }

                $chatId = (string) $e->migrateToChatId;
                $this->settings->work_chat_id = $chatId;
                $this->settings->save();
                $this->call('sendMessage', $this->messageParams($chatId, $html, $extra));
            }
        } catch (TelegramApiException $e) {
            if (! $override && $e->isPermanent()) {
                $this->recordWorkChatError($e->getMessage());
            }

            throw $e;
        }

        if (! $override && $this->settings->work_chat_error !== null) {
            $this->recordWorkChatError(null);
        }
    }

    public function recordWorkChatError(?string $error): void
    {
        $this->settings->work_chat_error = $error;
        $this->settings->save();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function messageParams(string $chatId, string $text, array $extra): array
    {
        return array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra);
    }

    private function redact(string $message, string $token): string
    {
        $message = str_replace($token, '***', $message);

        return preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $message) ?? $message;
    }
}
