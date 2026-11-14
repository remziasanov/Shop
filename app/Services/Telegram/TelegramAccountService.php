<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramAccount;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates and refreshes `telegram_accounts` rows (TG-06): on /start, any private message and Mini App entry (TM-02).
 */
class TelegramAccountService
{
    /**
     * @param  array<string, mixed>  $from  a Telegram `User` object (`id`, `first_name`, `last_name`, `username`, `language_code`)
     * @param  bool  $chatStarted  the user talked to the bot or allowed writing: the bot may message them (TG-05)
     */
    public function touch(array $from, bool $chatStarted = false): TelegramAccount
    {
        $userId = (int) ($from['id'] ?? 0);
        $profile = [
            'first_name' => $this->text($from['first_name'] ?? null, 255),
            'last_name' => $this->text($from['last_name'] ?? null, 255),
            'username' => $this->text($from['username'] ?? null, 255),
            'language_code' => $this->text($from['language_code'] ?? null, 10),
        ];

        $account = TelegramAccount::query()->where('telegram_user_id', $userId)->first();

        if ($account === null) {
            try {
                $account = TelegramAccount::query()->create(['telegram_user_id' => $userId, 'notify_enabled' => true] + $profile);
            } catch (UniqueConstraintViolationException) { // a parallel update of the same user created it first
                $account = TelegramAccount::query()->where('telegram_user_id', $userId)->firstOrFail();
            }
        }

        $account->fill($profile);
        $account->last_seen_at = now();

        if ($chatStarted) {
            $account->chat_started_at ??= now();
            // Writing to the bot means it is not blocked any more.
            $account->bot_blocked_at = null;
        }

        $account->save();

        return $account;
    }

    private function text(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
