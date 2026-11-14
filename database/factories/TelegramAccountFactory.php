<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fake Telegram users only: example names and a documentation-style phone.
 *
 * @extends Factory<TelegramAccount>
 */
class TelegramAccountFactory extends Factory
{
    protected $model = TelegramAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_user_id' => fake()->unique()->numberBetween(100_000_000, 9_000_000_000),
            'first_name' => 'Ivan',
            'last_name' => 'Example',
            'username' => 'example_'.fake()->unique()->numerify('####'),
            'language_code' => 'ru',
            'notify_enabled' => true,
        ];
    }

    /** The user started the chat, so the bot may write. */
    public function chatStarted(): static
    {
        return $this->state(fn (): array => ['chat_started_at' => now()]);
    }

    public function withPhone(): static
    {
        return $this->state(fn (): array => ['phone' => '+79990000000', 'phone_verified_at' => now()]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['bot_blocked_at' => now()]);
    }
}
