<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramMessage>
 */
class TelegramMessageFactory extends Factory
{
    protected $model = TelegramMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_account_id' => TelegramAccount::factory(),
            'chat_id' => null,
            'kind' => TelegramMessageKind::StatusChanged,
            'order_id' => null,
            'dedupe_key' => fake()->unique()->uuid(),
            'message_id' => null,
            'status' => TelegramMessageStatus::Queued,
            'error' => null,
            'sent_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => TelegramMessageStatus::Sent,
            'message_id' => fake()->numberBetween(1, 1_000_000),
            'sent_at' => now(),
        ]);
    }
}
