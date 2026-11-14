<?php

declare(strict_types=1);

use App\Models\TelegramAccount;
use App\Support\TelegramMode;

const TG_TEST_TOKEN = '123456:TEST-token_abc';

/**
 * A signed Mini App initData built the way Telegram builds it (TM-02): sorted `key=value` lines, HMAC with the key
 * HMAC("WebAppData", token), URL-encoded pairs with the `hash` last. The token is a fake one.
 *
 * @param  array<string, string>  $extra  more pairs (start_param, signature, ...)
 * @param  array<string, mixed>|null  $user  null: no `user` field at all
 */
function tgInitData(array $extra = [], ?array $user = ['id' => 777, 'first_name' => 'Ivan', 'last_name' => 'Petrov', 'username' => 'ivan_p', 'language_code' => 'ru'], ?int $authDate = null, string $token = TG_TEST_TOKEN): string
{
    $pairs = ['query_id' => 'AAH-test', 'auth_date' => (string) ($authDate ?? time())] + $extra;

    if ($user !== null) {
        $pairs['user'] = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    $check = $pairs;
    ksort($check, SORT_STRING);
    $lines = [];

    foreach ($check as $key => $value) {
        $lines[] = $key.'='.$value;
    }

    $pairs['hash'] = hash_hmac('sha256', implode("\n", $lines), hash_hmac('sha256', $token, 'WebAppData', true));

    $encoded = [];

    foreach ($pairs as $key => $value) {
        $encoded[] = urlencode((string) $key).'='.urlencode((string) $value);
    }

    return implode('&', $encoded);
}

/**
 * The session keys of a Mini App visitor, for `withSession()`.
 *
 * @return array<string, mixed>
 */
function tgSession(TelegramAccount $account): array
{
    return [
        TelegramMode::SESSION_KEY => true,
        TelegramMode::USER_KEY => $account->telegram_user_id,
        TelegramMode::ACCOUNT_KEY => $account->id,
        'channel' => 'telegram',
    ];
}
