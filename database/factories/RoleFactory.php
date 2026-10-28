<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use App\Services\Users\RoleService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'role-'.fake()->unique()->bothify('????##'),
            'name' => fake()->unique()->jobTitle(),
            'description' => null,
            'is_system' => false,
            'permissions_version' => 1,
        ];
    }

    /**
     * Grant exactly these permissions after the role is created.
     *
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions): void {
            app(RoleService::class)->syncPermissionsUnchecked($role, $permissions);
        });
    }
}
