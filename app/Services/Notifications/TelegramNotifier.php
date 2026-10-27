<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Settings\NotificationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Sends messages through the Telegram Bot API (NT-02). The token is read from the encrypted settings and is
 * never logged or put in exception messages.
 */
class TelegramNotifier
{
    public const TIMEOUT_SECONDS = 10;

    /** Telegram limit for a single text message. */
    public const MAX_LENGTH = 4096;

    public function __construct(private readonly NotificationSettings $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->telegram_bot_token) && filled($this->settings->telegram_chat_id);
    }

    /**
     * @param  ?string  $token  override of the saved token (test button with unsaved values)
     * @param  ?string  $chatId  override of the saved chat id
     *
     * @throws TelegramException when the API call fails (the queue job then retries)
     */
    public function send(string $html, ?string $token = null, ?string $chatId = null): void
    {
        $token = (string) ($token ?: $this->settings->telegram_bot_token);
        $chatId = (string) ($chatId ?: $this->settings->telegram_chat_id);

        if ($token === '' || $chatId === '') {
            throw new TelegramException(__('notifications.telegram_not_configured'));
        }

        if (mb_strlen($html) > self::MAX_LENGTH) {
            $html = mb_substr($html, 0, self::MAX_LENGTH - 1).'…';
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $html,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);
        } catch (ConnectionException $e) { // not chained: the original message may contain the URL with the token
            throw new TelegramException($this->redact($e->getMessage(), $token));
        }

        if ($response->failed() || $response->json('ok') !== true) {
            $description = $this->redact((string) ($response->json('description') ?? ''), $token);

            throw new TelegramException(
                'Telegram API error (HTTP '.$response->status().')'.($description !== '' ? ': '.$description : ''),
            );
        }
    }

    private function redact(string $message, string $token): string
    {
        $message = str_replace($token, '***', $message);

        return preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $message) ?? $message;
    }
}
