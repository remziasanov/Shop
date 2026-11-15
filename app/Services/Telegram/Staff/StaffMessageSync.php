<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Jobs\EditTelegramMessage;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramRateLimitedException;
use App\Services\Users\PermissionService;
use Throwable;

/**
 * Keeps the staff messages about a request in step with it (TS-03, TS-04): the message of a tapped button is edited
 * at once (one Bot API call with a 2 s timeout, a queue job when it fails, NF-60), the other copies logged in
 * `telegram_messages` (work chat, personal chats) are edited by queue jobs.
 */
final class StaffMessageSync
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly StaffOrderNotifier $notifier,
        private readonly StaffAccess $access,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Redraws the message the staff member just tapped, right after the transaction was committed (TS-04).
     */
    public function redrawNow(string|int $chatId, int $messageId, Order $order, bool $cancelArmed = false, bool $forWorkChat = true): void
    {
        $content = $this->notifier->content($order, $cancelArmed, $forWorkChat);
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $content['text'],
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $content['markup'],
        ];

        try {
            $this->client->call('editMessageText', $params, null, TelegramClient::WEBHOOK_TIMEOUT_SECONDS);
        } catch (Throwable $e) {
            // A timeout, a network error, a 5xx or a rate limit: the queue redraws. Other 4xx answers (unchanged text,
            // the message is gone) are final.
            $retryable = $e instanceof TelegramRateLimitedException
                || ($e instanceof TelegramApiException && ($e->status === null || $e->status >= 500));

            if ($retryable) {
                EditTelegramMessage::dispatch((string) $chatId, $messageId, $content['text'], $content['markup'], 'HTML');
            }
        }
    }

    /**
     * Queues the redraw of every other logged staff message about the request. A personal copy of a staff member who has
     * unlinked Telegram or lost `orders.view` is left alone (the work chat copy is a shared one).
     */
    public function redrawCopies(Order $order, string|int|null $exceptChatId = null, ?int $exceptMessageId = null): void
    {
        $copies = TelegramMessage::query()
            ->where('order_id', $order->id)
            ->where('kind', TelegramMessageKind::StaffNewOrder->value)
            ->where('status', TelegramMessageStatus::Sent->value)
            ->whereNotNull('chat_id')
            ->whereNotNull('message_id')
            ->get();

        if ($copies->isEmpty()) {
            return;
        }

        $workContent = $this->notifier->content($order);
        $personalContent = $this->notifier->content($order, forWorkChat: false);

        foreach ($copies as $copy) {
            if ($exceptMessageId !== null && $copy->message_id === $exceptMessageId && (string) $copy->chat_id === (string) $exceptChatId) {
                continue;
            }

            if ($copy->telegram_account_id !== null && ! $this->personalCopyAllowed($copy)) {
                continue;
            }

            $content = $copy->telegram_account_id !== null ? $personalContent : $workContent;

            EditTelegramMessage::dispatch((string) $copy->chat_id, (int) $copy->message_id, $content['text'], $content['markup'], 'HTML');
        }
    }

    private function personalCopyAllowed(TelegramMessage $copy): bool
    {
        $account = TelegramAccount::query()->find($copy->telegram_account_id);

        if ($account === null) {
            return false;
        }

        $user = $this->access->linkedUser($account);

        return $user !== null && $this->permissions->allows($user, 'orders.view');
    }
}
