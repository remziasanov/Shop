<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fake customers only (the repository is public): example.com addresses, a documentation-style phone range. Every
 * factory customer has the same known test password (see the definition).
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'customer_group_id' => fn (): int => CustomerGroup::retail()->id ?? (int) CustomerGroup::query()->where('code', CustomerGroup::RETAIL)->value('id'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => '+7900'.fake()->unique()->numerify('#######'),
            'password' => static::$password ??= Hash::make('password-123'),
            'remember_token' => Str::random(10),
            'auth_version' => 1,
            'is_blocked' => false,
            'consent_at' => now(),
            'consent_ip' => '192.0.2.1',
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function blocked(?string $reason = null): static
    {
        return $this->state(fn (): array => ['is_blocked' => true, 'blocked_reason' => $reason]);
    }

    public function inGroup(CustomerGroup $group): static
    {
        return $this->state(fn (): array => ['customer_group_id' => $group->id]);
    }
}
