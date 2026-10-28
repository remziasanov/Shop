<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function rolesMigration(): object
{
    return require base_path('database/migrations/2026_11_10_100004_replace_user_role_with_role_id.php');
}

/**
 * RB-06, RB-15: system roles, Manager defaults and the users.role -> role_id mapping in both directions.
 */
describe('roles migration', function (): void {
    it('creates the system roles and the manager permissions of the TZ matrix', function (): void {
        $admin = Role::query()->where('code', 'admin')->firstOrFail();
        $manager = Role::query()->where('code', 'manager')->firstOrFail();

        expect($admin->is_system)->toBeTrue()
            ->and($admin->name)->toBe('Администратор')
            ->and($admin->storedPermissions())->toBe([])
            ->and($manager->is_system)->toBeTrue()
            ->and($manager->name)->toBe('Менеджер')
            ->and($manager->storedPermissions())->toEqualCanonicalizing(PermissionRegistry::managerDefaults())
            ->and(Schema::hasColumn('users', 'role'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'role_id'))->toBeTrue();
    });

    it('maps users back to role codes on down and restores them on up', function (): void {
        $custom = Role::factory()->create(['code' => 'content']);
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $editor = User::factory()->withRole($custom)->create();

        $migration = rolesMigration();
        $migration->down();

        expect(Schema::hasColumn('users', 'role'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'role_id'))->toBeFalse()
            ->and(DB::table('users')->where('id', $admin->id)->value('role'))->toBe('admin')
            ->and(DB::table('users')->where('id', $manager->id)->value('role'))->toBe('manager')
            // Custom roles fall back to manager (RB-06).
            ->and(DB::table('users')->where('id', $editor->id)->value('role'))->toBe('manager')
            ->and(DB::table('roles')->where('is_system', true)->count())->toBe(0);

        // A legacy install: roles as plain strings.
        DB::table('users')->where('id', $editor->id)->update(['role' => 'admin']);

        $migration->up();

        $adminRole = (int) Role::query()->where('code', 'admin')->value('id');
        $managerRole = (int) Role::query()->where('code', 'manager')->value('id');

        expect(Schema::hasColumn('users', 'role'))->toBeFalse()
            ->and((int) DB::table('users')->where('id', $admin->id)->value('role_id'))->toBe($adminRole)
            ->and((int) DB::table('users')->where('id', $manager->id)->value('role_id'))->toBe($managerRole)
            ->and((int) DB::table('users')->where('id', $editor->id)->value('role_id'))->toBe($adminRole)
            ->and(Role::query()->where('code', 'manager')->firstOrFail()->storedPermissions())->toEqualCanonicalizing(PermissionRegistry::managerDefaults())
            ->and(Role::query()->where('code', 'content')->exists())->toBeTrue();
    });

    it('gives every user without a recognizable role the manager role on up', function (): void {
        $migration = rolesMigration();
        $migration->down();

        DB::table('users')->insert([
            'name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'x', 'role' => 'something-else',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        expect((int) DB::table('users')->where('email', 'legacy@example.com')->value('role_id'))
            ->toBe(roleId(SystemRole::Manager));
    });
});
