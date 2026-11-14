<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Services\Telegram\Handlers\CallbackQueryHandler;
use App\Services\Telegram\Handlers\ContactHandler;
use App\Services\Telegram\Handlers\HelpCommand;
use App\Services\Telegram\Handlers\MyChatMemberHandler;
use App\Services\Telegram\Handlers\OrdersCommand;
use App\Services\Telegram\Handlers\StartCommand;
use App\Services\Telegram\Handlers\StopCommand;
use Illuminate\Support\Facades\Cache;

/**
 * Routes one webhook update (TG-05). Everything here must fit in 4 seconds, so it does no outgoing Bot API call
 * except the redraw of a pressed button's message: the answer is a Bot API method array that the webhook returns in
 * its response body (null = nothing to answer). Group messages (the work chat included) are ignored.
 */
class TelegramUpdateHandler
{
    /** Seconds between two automatic replies to one chat. */
    public const AUTOREPLY_PAUSE_SECONDS = 600;

    public function __construct(
        private readonly TelegramAccountService $accounts,
        private readonly StartCommand $start,
        private readonly OrdersCommand $orders,
        private readonly HelpCommand $help,
        private readonly StopCommand $stop,
        private readonly ContactHandler $contacts,
        private readonly MyChatMemberHandler $chatMembers,
        private readonly CallbackQueryHandler $callbacks,
        private readonly TelegramMessages $messages,
    ) {}

    /**
     * Type name stored in `telegram_updates.type`.
     *
     * @param  array<string, mixed>  $update
     */
    public static function typeOf(array $update): string
    {
        foreach (['message', 'callback_query', 'my_chat_member'] as $type) {
            if (isset($update[$type])) {
                return $type;
            }
        }

        return 'other';
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    public function handle(array $update): ?array
    {
        return match (self::typeOf($update)) {
            'message' => is_array($update['message']) ? $this->message($update['message']) : null,
            'callback_query' => is_array($update['callback_query']) ? $this->callbacks->handle($update['callback_query']) : null,
            'my_chat_member' => $this->chatMember($update['my_chat_member']),
            default => null,
        };
    }

    private function chatMember(mixed $update): null
    {
        if (is_array($update)) {
            $this->chatMembers->handle($update);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private function message(array $message): ?array
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : null;

        // Groups (except events of the work chat that need nothing) and channels are ignored; so are other bots.
        if (($chat['type'] ?? '') !== 'private' || $from === null || ($from['is_bot'] ?? false) === true) {
            return null;
        }

        // Any message in a private chat means the bot may write to this user (also `write_access_allowed`).
        $account = $this->accounts->touch($from, true);

        if (isset($message['write_access_allowed'])) {
            return null;
        }

        if (is_array($message['contact'] ?? null)) {
            return $this->contacts->handle($account, $message['contact'], (int) $from['id']);
        }

        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';

        if ($text !== '' && $text[0] === '/' && preg_match('#^/([A-Za-z_]+)(?:@\w+)?(?:\s+(.*))?$#su', $text, $m)) {
            switch (strtolower($m[1])) {
                case 'start':
                    return $this->start->handle($account, trim($m[2] ?? ''));
                case 'orders':
                    return $this->orders->handle($account);
                case 'help':
                    return $this->help->handle($account);
                case 'stop':
                    return $this->stop->handle($account);
            }
        }

        // Other messages: «Откройте магазин или позвоните …», at most once per 10 minutes per chat.
        if (! Cache::add('telegram:autoreply:'.$account->telegram_user_id, 1, self::AUTOREPLY_PAUSE_SECONDS)) {
            return null;
        }

        return $this->messages->reply($account, $this->messages->autoReplyText(), $this->messages->openShopMarkup());
    }
}
