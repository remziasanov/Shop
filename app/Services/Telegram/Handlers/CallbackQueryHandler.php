<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Services\Telegram\TelegramAccountService;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use Throwable;

/**
 * `callback_query` (TG-05): the link confirmation buttons (TG-07). Other buttons belong to stage 18 (TS-03) and
 * get a polite «Скоро» for now. The answer goes into the webhook response body; the redraw of the question
 * message is the one synchronous Bot API call allowed by NF-60.
 */
class CallbackQueryHandler
{
    public function __construct(
        private readonly TelegramAccountService $accounts,
        private readonly TelegramLinkService $links,
        private readonly TelegramClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function handle(array $query): ?array
    {
        $id = (string) ($query['id'] ?? '');
        $from = is_array($query['from'] ?? null) ? $query['from'] : null;
        $data = (string) ($query['data'] ?? '');

        if ($id === '' || $from === null) {
            return null;
        }

        if (preg_match('/^(lc|ln):(\d+):([0-9a-f]{8})$/', $data, $m)) {
            $account = $this->accounts->touch($from);
            $result = $this->links->answerConfirmation($account, $m[1], (int) $m[2], $m[3]);

            if ($result === null) {
                return $this->answer($id, __('telegram.link.expired_toast'));
            }

            $this->redraw($query, $result['text'], $result['markup']);

            return $this->answer($id, $result['toast']);
        }

        // TS-03 (stage 18) will route its action buttons here.
        return $this->answer($id, __('telegram.callback.soon'));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $id, string $text): array
    {
        return ['method' => 'answerCallbackQuery', 'callback_query_id' => $id, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $markup
     */
    private function redraw(array $query, string $text, ?array $markup): void
    {
        $chatId = $query['message']['chat']['id'] ?? null;
        $messageId = $query['message']['message_id'] ?? null;

        if ($chatId === null || $messageId === null) {
            return;
        }

        try {
            $this->client->call('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => $markup ?? ['inline_keyboard' => []],
            ]);
        } catch (Throwable) {
            // The toast of answerCallbackQuery already told the result; the old keyboard simply stays.
        }
    }
}
