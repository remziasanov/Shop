<?php

declare(strict_types=1);

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramMessages;
use App\Support\PhoneNormalizer;

/**
 * A shared contact (TG-09): accepted only when it is the sender's own (`contact.user_id` = `from.id`), not a
 * forwarded one. Saved as `phone` with `phone_verified_at`; the Mini App picks it up from `GET /tg/me`.
 */
class ContactHandler
{
    public function __construct(private readonly TelegramMessages $messages) {}

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>|null
     */
    public function handle(TelegramAccount $account, array $contact, int $fromId): ?array
    {
        $phone = is_string($contact['phone_number'] ?? null) ? $this->normalize($contact['phone_number']) : null;

        if ((int) ($contact['user_id'] ?? 0) !== $fromId || $phone === null) {
            return $this->messages->reply($account, __('telegram.bot.contact_not_own'), ['remove_keyboard' => true]);
        }

        $account->phone = $phone;
        $account->phone_verified_at = now();
        $account->save();

        return $this->messages->reply($account, __('telegram.bot.contact_saved'), ['remove_keyboard' => true]);
    }

    /**
     * Russian numbers to +7XXXXXXXXXX; other countries as digits with a plus (TG-09).
     */
    public function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return PhoneNormalizer::normalize($input) ?? '+'.$digits;
    }
}
