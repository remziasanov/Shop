<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role_id' => fn (): int => self::systemRoleId(SystemRole::Manager),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role_id' => self::systemRoleId(SystemRole::Admin)]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => ['role_id' => self::systemRoleId(SystemRole::Manager)]);
    }

    public function withRole(Role $role): static
    {
        return $this->state(fn (array $attributes) => ['role_id' => $role->getKey()]);
    }

    /**
     * A user of a new custom role holding exactly these permissions.
     *
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::factory()->withPermissions($permissions)->create()->getKey(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    private static function systemRoleId(SystemRole $role): int
    {
        return (int) Role::query()->where('code', $role->value)->value('id');
    }
}
