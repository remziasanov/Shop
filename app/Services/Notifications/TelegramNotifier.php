<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Notifications\Messages\OrderMessage;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Settings\TelegramSettings;

/**
 * Sends the shop notifications to the work chat (NT-02) through {@see TelegramClient}: the token is read from the
 * encrypted settings and is never logged or put in exception messages.
 */
class TelegramNotifier
{
    /** Telegram limit for a single text message. */
    public const MAX_LENGTH = 4096;

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramClient $client,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->bot_token) && filled($this->settings->work_chat_id);
    }

    /** Shop notifications to the work chat are switched on in «Настройки → Telegram» and the bot and chat are set. */
    public function isEnabled(): bool
    {
        return $this->settings->enabled && $this->isConfigured();
    }

    /**
     * @param  ?string  $token  override of the saved token (test button with unsaved values)
     * @param  ?string  $chatId  override of the saved chat id
     *
     * @throws TelegramException when the API call fails (the queue job then retries, except a {@see TelegramApiException::isPermanent()} one)
     */
    public function send(string $html, ?string $token = null, ?string $chatId = null): void
    {
        if ((string) ($token ?: $this->settings->bot_token) === '' || (string) ($chatId ?: $this->settings->work_chat_id) === '') {
            throw new TelegramException(__('notifications.telegram_not_configured'));
        }

        // Messages are built within the limit (see OrderMessage). Last resort: plain text cut on visible characters.
        if (OrderMessage::visibleLength($html) > self::MAX_LENGTH) {
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = HtmlEscaper::e(OrderMessage::cutUtf16($text, self::MAX_LENGTH - 1).'…');
        }

        // Overrides (the settings test button) skip the limits and leave the work chat state alone.
        $this->client->sendToWorkChat($html, [], filled($token) ? $token : null, filled($chatId) ? $chatId : null);
    }
}
