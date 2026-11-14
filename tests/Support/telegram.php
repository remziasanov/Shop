<?php

declare(strict_types=1);

use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const TG_SECRET = 'test-webhook-secret';

/**
 * A connected bot: fake token, secret, username. Never a real token.
 *
 * @param  array<string, mixed>  $values
 */
function connectTelegramBot(array $values = []): TelegramSettings
{
    $settings = app(TelegramSettings::class);
    $settings->bot_token = '123456:TEST-token_abc';
    $settings->webhook_secret = TG_SECRET;
    $settings->bot_id = 123456;
    $settings->bot_username = 'test_shop_bot';
    $settings->connected_at = now()->toIso8601String();

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

/**
 * @param  array<string, mixed>  $update
 * @param  array<string, string>  $headers
 */
function telegramWebhook(array $update, ?array $headers = null): TestResponse
{
    return test()->call('POST', '/telegram/webhook', [], [], [], [
        'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => TG_SECRET,
        'CONTENT_TYPE' => 'application/json',
        ...($headers ?? []),
    ], json_encode($update, JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function tgMessage(int $updateId, string $text, int $userId = 555, array $extra = []): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => $updateId,
            'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ivan', 'username' => 'ivan_tg', 'language_code' => 'ru'],
            'chat' => ['id' => $userId, 'type' => 'private'],
            'date' => time(),
            'text' => $text,
            ...$extra,
        ],
    ];
}

function fakeTelegramApi(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
}

/**
 * A callback_query update (inline button press) for the webhook.
 *
 * @return array<string, mixed>
 */
function pressButton(int $updateId, string $data, int $userId = 555): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => ['id' => 'cb'.$updateId, 'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ivan', 'username' => 'ivan_tg'], 'data' => $data, 'message' => ['message_id' => 9, 'chat' => ['id' => $userId, 'type' => 'private']]],
    ];
}
