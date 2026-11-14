<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Enums\OrderStatus;
use App\Enums\StaffOrderAction;
use App\Services\Telegram\Staff\StaffAccess;
use App\Services\Telegram\Staff\StaffActionResult;
use App\Services\Telegram\Staff\StaffMessageSync;
use App\Services\Telegram\Staff\StaffOrderActions;
use App\Settings\TelegramSettings;

/**
 * The action buttons of the staff messages: `o:{action}:{order id}:{version}` (TS-03, TS-04). Order of the checks:
 * the staff member by `from.id`, the chat of the message (the shop work chat or the member's own private chat), the
 * feature switch, then {@see StaffOrderActions} (rights, version, two taps, the change itself). The answer is the body
 * of the webhook response; the message is redrawn right after the commit.
 */
class StaffCallbackHandler
{
    public const PREFIX = 'o';

    public function __construct(
        private readonly StaffAccess $access,
        private readonly StaffOrderActions $actions,
        private readonly StaffMessageSync $sync,
        private readonly TelegramSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $query  the `callback_query` update
     * @return array<string, mixed>
     */
    public function handle(array $query): array
    {
        $id = (string) ($query['id'] ?? '');
        $from = is_array($query['from'] ?? null) ? $query['from'] : [];
        $data = (string) ($query['data'] ?? '');
        $message = is_array($query['message'] ?? null) ? $query['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];

        $fromId = $from['id'] ?? null;
        $user = is_int($fromId) ? $this->access->userByTelegramId($fromId) : null;

        if ($user === null) {
            return $this->answer($id, __('telegram_staff.toast.not_linked'), alert: true);
        }

        $chatId = $chat['id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (! is_int($chatId) || ! is_int($messageId) || ! $this->fromAllowedChat($chat, $chatId, $fromId)) {
            return $this->answer($id, __('telegram_staff.toast.wrong_chat'), alert: true);
        }

        if (! $this->access->buttonsEnabled()) {
            return $this->answer($id, __('telegram_staff.toast.disabled'), alert: true);
        }

        if (! preg_match('/^o:([a-z]{2}):(\d{1,18}):(\d{1,9})$/', $data, $m) || ($action = StaffOrderAction::tryFrom($m[1])) === null || $action->isWorkplaceOnly()) {
            return $this->answer($id, __('telegram_staff.toast.bad_data'));
        }

        $result = $this->actions->perform($user, (int) $m[2], $action, (int) $m[3], 'chat');

        if ($result->order !== null && in_array($result->outcome, [StaffActionResult::DONE, StaffActionResult::STALE, StaffActionResult::ARMED], true)) {
            $this->sync->redrawNow($chatId, $messageId, $result->order, cancelArmed: $result->outcome === StaffActionResult::ARMED);

            if ($result->isDone() || $result->outcome === StaffActionResult::STALE) {
                $this->sync->redrawCopies($result->order, $chatId, $messageId);
            }
        }

        return $this->answer($id, $this->toast($result));
    }

    /**
     * TS-03: only the work chat of the shop or the private chat of this very staff member.
     *
     * @param  array<string, mixed>  $chat
     */
    private function fromAllowedChat(array $chat, int $chatId, mixed $fromId): bool
    {
        if (($chat['type'] ?? '') === 'private') {
            return $chatId === $fromId;
        }

        $work = trim((string) $this->settings->work_chat_id);

        return $work !== '' && ctype_digit(ltrim($work, '-')) && (int) $work === $chatId;
    }

    private function toast(StaffActionResult $result): string
    {
        return match ($result->outcome) {
            StaffActionResult::DONE => $this->doneToast($result),
            StaffActionResult::ARMED => __('telegram_staff.toast.confirm_cancel'),
            StaffActionResult::STALE => __('telegram_staff.toast.stale'),
            StaffActionResult::FORBIDDEN => __('telegram_staff.toast.forbidden'),
            StaffActionResult::NOT_FOUND => __('telegram_staff.toast.not_found'),
            default => $result->message !== null && $result->message !== '' ? mb_substr($result->message, 0, 190) : __('telegram_staff.toast.failed'),
        };
    }

    private function doneToast(StaffActionResult $result): string
    {
        $order = $result->order;

        if ($result->action === StaffOrderAction::Extend) {
            return __('telegram_staff.toast.done_extended', ['date' => $order?->reserved_until?->format('d.m.Y H:i') ?? '']);
        }

        $key = match ($order?->status) {
            OrderStatus::Confirmed => 'done_confirmed',
            OrderStatus::Reserved => 'done_reserved',
            OrderStatus::Ready => 'done_ready',
            OrderStatus::InProgress => 'done_in_progress',
            OrderStatus::Closed => 'done_closed',
            OrderStatus::Cancelled => 'done_cancelled',
            default => 'done_default',
        };

        return __('telegram_staff.toast.'.$key);
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $id, string $text, bool $alert = false): array
    {
        return array_filter([
            'method' => 'answerCallbackQuery',
            'callback_query_id' => $id,
            'text' => mb_substr($text, 0, 200),
            'show_alert' => $alert ? true : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
