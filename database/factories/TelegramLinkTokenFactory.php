<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TelegramLinkPurpose;
use App\Models\TelegramLinkToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramLinkToken>
 */
class TelegramLinkTokenFactory extends Factory
{
    protected $model = TelegramLinkToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', Str::random(32)),
            'purpose' => TelegramLinkPurpose::Customer,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()]);
    }
}
