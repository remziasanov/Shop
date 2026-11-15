<?php

declare(strict_types=1);

use App\Models\TelegramAccount;
use App\Models\User;
use App\Support\TelegramMode;
use Illuminate\Support\Facades\Http;

/**
 * A connected bot with the staff features switched on and a work chat (-1001).
 *
 * @param  array<string, mixed>  $values
 */
function staffBot(array $values = []): void
{
    connectTelegramBot($values + [
        'enabled' => true,
        'work_chat_id' => '-1001',
        'work_chat_buttons_enabled' => true,
        'staff_workplace_enabled' => true,
        'miniapp_enabled' => true,
    ]);
}

/**
 * An active staff member linked to a Telegram user (the confirmed `s_` link).
 *
 * @param  list<string>  $permissions
 * @return array{0: User, 1: TelegramAccount}
 */
function linkedStaff(array $permissions = ['orders.view', 'orders.update'], int $telegramUserId = 555, string $name = 'Иванов'): array
{
    $user = User::factory()->withPermissions($permissions)->create(['name' => $name]);
    $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => $telegramUserId]);
    $account->forceFill(['user_id' => $user->id])->save();

    return [$user, $account];
}

/**
 * A button press (`callback_query`) in a chat.
 *
 * @return array<string, mixed>
 */
function pressStaffButton(int $updateId, string $data, int $fromId = 555, int $chatId = -1001, string $chatType = 'supergroup', int $messageId = 9): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => [
            'id' => 'cb'.$updateId,
            'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Ivan', 'username' => 'ivan_tg'],
            'data' => $data,
            'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId, 'type' => $chatType]],
        ],
    ];
}

function fakeTelegramSend(int $messageId = 77, int $chatId = -1001): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => $messageId, 'chat' => ['id' => $chatId]]])]);
}

/**
 * The Bot API calls the test made, as `[method => list of payloads]`.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function telegramCalls(): array
{
    $calls = [];

    foreach (Http::recorded() as [$request]) {
        if (preg_match('#api\.telegram\.org/bot[^/]+/(\w+)#', $request->url(), $m)) {
            $calls[$m[1]][] = $request->data();
        }
    }

    return $calls;
}

/**
 * The session of a staff member who entered the «Рабочее место» (for `withSession()` or `session()`).
 *
 * @return array<string, mixed>
 */
function staffSession(User $user, int $telegramUserId = 555, ?int $at = null): array
{
    return [
        auth()->guard('telegram_staff')->getName() => $user->id,
        TelegramMode::SESSION_KEY => true,
        TelegramMode::USER_KEY => $telegramUserId,
        TelegramMode::STAFF_KEY => true,
        TelegramMode::STAFF_AT_KEY => $at ?? time(),
    ];
}
