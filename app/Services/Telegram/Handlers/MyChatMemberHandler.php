<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramClient;
use App\Settings\TelegramSettings;

/**
 * `my_chat_member` (TG-05): the bot was blocked / unblocked in a private chat, or removed from / added to the work chat.
 */
class MyChatMemberHandler
{
    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $update  the `my_chat_member` object
     */
    public function handle(array $update): void
    {
        $chat = is_array($update['chat'] ?? null) ? $update['chat'] : [];
        $status = (string) ($update['new_chat_member']['status'] ?? '');

        if (($chat['type'] ?? '') === 'private') {
            $this->private((int) ($chat['id'] ?? 0), $status);

            return;
        }

        $this->group((string) ($chat['id'] ?? ''), $status);
    }

    private function private(int $userId, string $status): void
    {
        $account = TelegramAccount::query()->where('telegram_user_id', $userId)->first();

        if ($account === null) {
            return;
        }

        if ($status === 'kicked') {
            $account->bot_blocked_at = now();
            $account->save();
        } elseif ($status === 'member') {
            $account->bot_blocked_at = null;
            $account->save();
        }
    }

    /**
     * The telegram_accounts rows are not touched for a group (TG-05).
     */
    private function group(string $chatId, string $status): void
    {
        if ($chatId === '' || (string) $this->settings->work_chat_id !== $chatId) {
            return;
        }

        if (in_array($status, ['left', 'kicked'], true)) {
            $this->client->recordWorkChatError(__('telegram.admin.work_chat_removed'));
        } elseif (in_array($status, ['member', 'administrator'], true) && $this->settings->work_chat_error !== null) {
            $this->client->recordWorkChatError(null);
        }
    }
}
