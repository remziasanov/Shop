<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Users\PermissionService;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Sends the staff message about a new request with its buttons (TS-01) to the work chat and to the personal chats of
 * the staff who asked for them (TS-08), and logs every message in `telegram_messages` (chat id, message id) so that it
 * can be redrawn after an action (TS-03). A message is written before it is sent; a repeated job finds it `sent` and does
 * nothing. Runs only inside queue jobs (NF-60).
 */
final class StaffOrderNotifier
{
    public function __construct(
        private readonly StaffOrderMessage $message,
        private readonly TelegramNotifier $notifier,
        private readonly TelegramClient $client,
        private readonly PermissionService $permissions,
        private readonly StaffAccess $access,
    ) {}

    /**
     * NT-02, TS-01: the work chat message.
     *
     * @throws TelegramApiException
     */
    public function sendToWorkChat(Order $order): void
    {
        $log = $this->log($order, 'staff_new:'.$order->id.':work', null);

        if ($log === null) {
            return;
        }

        $this->deliver($log, function () use ($order): array {
            $markup = $this->message->markup($order);

            return $this->notifier->sendWithExtra($this->message->text($order), $markup === null ? [] : ['reply_markup' => $markup]);
        });
    }

    /**
     * TS-08: a personal message about a new request. The right to see requests is checked here, right before sending.
     *
     * @return bool whether a message was sent (or had been)
     *
     * @throws TelegramApiException
     */
    public function sendPersonal(Order $order, User $user): bool
    {
        if (! $this->access->botReady() || ! $user->is_active || ! $user->telegram_personal_notify || ! $this->permissions->allows($user, 'orders.view')) {
            return false;
        }

        $account = TelegramAccount::query()->where('user_id', $user->id)->first();

        if ($account === null || $account->chat_started_at === null || $account->bot_blocked_at !== null) {
            return false;
        }

        $log = $this->log($order, 'staff_new:'.$order->id.':u'.$user->id, $account->id);

        if ($log === null) {
            return true;
        }

        $this->deliver($log, function () use ($order, $account): array {
            $markup = $this->message->markup($order);

            return $this->client->sendMessage((string) $account->telegram_user_id, $this->message->text($order), $markup === null ? [] : ['reply_markup' => $markup]);
        });

        return true;
    }

    /**
     * TS-08: active staff with the personal switch on, a linked Telegram the bot may write to and the right to see
     * requests. Empty while the bot is not connected (NF-63).
     *
     * @return Collection<int, User>
     */
    public function personalRecipients(): Collection
    {
        if (! $this->access->botReady()) {
            return new Collection;
        }

        return User::query()
            ->where('is_active', true)
            ->where('telegram_personal_notify', true)
            ->whereHas('telegramAccount', fn ($query) => $query->whereNotNull('chat_started_at')->whereNull('bot_blocked_at'))
            ->get()
            ->filter(fn (User $user): bool => $this->permissions->allows($user, 'orders.view'))
            ->values();
    }

    /**
     * The log row of a message about to be sent; null when it was sent already.
     */
    private function log(Order $order, string $dedupeKey, ?int $accountId): ?TelegramMessage
    {
        $log = TelegramMessage::query()->firstOrCreate(['dedupe_key' => $dedupeKey], [
            'telegram_account_id' => $accountId,
            'kind' => TelegramMessageKind::StaffNewOrder,
            'order_id' => $order->id,
            'status' => TelegramMessageStatus::Queued,
        ]);

        return $log->status === TelegramMessageStatus::Sent ? null : $log;
    }

    /**
     * @param  callable(): array<mixed>  $send
     */
    private function deliver(TelegramMessage $log, callable $send): void
    {
        try {
            $sent = $send();
        } catch (Throwable $e) {
            // The error text holds no token (TelegramClient redacts it) and no personal data.
            $log->forceFill(['status' => TelegramMessageStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }

        $chat = $sent['chat']['id'] ?? null;
        $messageId = $sent['message_id'] ?? null;

        $log->forceFill([
            'status' => TelegramMessageStatus::Sent,
            'chat_id' => is_numeric($chat) ? (int) $chat : $log->chat_id,
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
            'error' => null,
            'sent_at' => now(),
        ])->save();
    }

    /**
     * The text and the keyboard of a request for editing (the redraw after an action).
     *
     * @return array{text: string, markup: array<string, mixed>}
     */
    public function content(Order $order, bool $cancelArmed = false): array
    {
        return [
            'text' => $this->message->text($order),
            'markup' => $this->message->markup($order, $cancelArmed) ?? ['inline_keyboard' => []],
        ];
    }
}
