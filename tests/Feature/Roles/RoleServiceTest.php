<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Exceptions\StaffAccessException;
use App\Models\AdminAuditEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\PermissionService;
use App\Services\Users\RoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(RoleService::class);
});

/**
 * RB-01, RB-07, RB-08, RB-12.
 */
describe('role service', function (): void {
    it('creates a role with permissions and writes the journal', function (): void {
        $role = $this->service->create(['code' => 'content', 'name' => 'Контент-менеджер'], ['pages.view', 'pages.update', 'bogus.key'], $this->admin);

        expect($role->is_system)->toBeFalse()
            ->and($role->storedPermissions())->toBe(['pages.update', 'pages.view'])
            ->and(AdminAuditEntry::query()->where('action', AuditAction::RoleCreated->value)->where('subject_id', $role->id)->exists())->toBeTrue();
    });

    it('rejects a malformed or duplicate code', function (): void {
        $this->service->create(['code' => 'content', 'name' => 'A'], [], $this->admin);

        expect(fn () => $this->service->create(['code' => 'content', 'name' => 'B'], [], $this->admin))->toThrow(StaffAccessException::class)
            ->and(fn () => $this->service->create(['code' => 'Bad Code!', 'name' => 'B'], [], $this->admin))->toThrow(StaffAccessException::class);
    });

    it('never deletes a system role', function (): void {
        $manager = Role::query()->where('code', 'manager')->firstOrFail();

        expect(fn () => $this->service->delete($manager, $this->admin))->toThrow(StaffAccessException::class)
            ->and(Role::query()->whereKey($manager->id)->exists())->toBeTrue();
    });

    it('keeps the code of a system role and the permissions of the administrator', function (): void {
        $manager = Role::query()->where('code', 'manager')->firstOrFail();
        $adminRole = Role::query()->where('code', 'admin')->firstOrFail();

        expect(fn () => $this->service->update($manager, ['code' => 'boss'], null, $this->admin))->toThrow(StaffAccessException::class)
            ->and(fn () => $this->service->update($adminRole, [], ['products.view'], $this->admin))->toThrow(StaffAccessException::class);

        // The name of a system role may change; the Administrator role still stores nothing.
        $this->service->update($manager, ['name' => 'Старший менеджер'], null, $this->admin);

        expect($manager->refresh()->name)->toBe('Старший менеджер')
            ->and($adminRole->storedPermissions())->toBe([]);
    });

    it('refuses to delete a role that has users and lists them', function (): void {
        $role = Role::factory()->create();
        User::factory()->withRole($role)->create(['name' => 'Иван Петров']);

        expect(fn () => $this->service->delete($role, $this->admin))->toThrow(StaffAccessException::class, 'Иван Петров')
            ->and(Role::query()->whereKey($role->id)->exists())->toBeTrue();
    });

    it('deletes an unused custom role and records it', function (): void {
        $role = Role::factory()->withPermissions(['pages.view'])->create();

        $this->service->delete($role, $this->admin);

        expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
            ->and(AdminAuditEntry::query()->where('action', AuditAction::RoleDeleted->value)->exists())->toBeTrue()
            ->and(DB::table('role_permissions')->where('role_id', $role->id)->count())->toBe(0);
    });

    it('stops staff from granting rights they do not hold (escalation)', function (): void {
        $actor = staffWith(['roles.view', 'roles.create', 'roles.update', 'products.view']);

        expect(fn () => $this->service->create(['code' => 'sneaky', 'name' => 'S'], ['products.view', 'orders.delete'], $actor))
            ->toThrow(StaffAccessException::class);

        $role = $this->service->create(['code' => 'viewer', 'name' => 'V'], ['products.view'], $actor);

        expect($role->storedPermissions())->toBe(['products.view'])
            ->and(fn () => $this->service->update($role, [], ['products.view', 'prices.bulk'], $actor))->toThrow(StaffAccessException::class);
    });

    it('lets an administrator grant anything', function (): void {
        $role = $this->service->create(['code' => 'all', 'name' => 'All'], ['users.delete', 'system.view'], $this->admin);

        expect($role->storedPermissions())->toBe(['system.view', 'users.delete']);
    });

    it('does not let staff change their own role', function (): void {
        $actor = staffWith(['roles.update', 'products.view']);
        $own = Role::query()->findOrFail($actor->role_id);

        expect(fn () => $this->service->update($own, ['name' => 'Mine'], ['products.view', 'roles.update'], $actor))
            ->toThrow(StaffAccessException::class);
    });

    it('requires the matching roles.* permission in the service itself', function (): void {
        $manager = User::factory()->manager()->create();
        $role = Role::factory()->create();

        expect(fn () => $this->service->create(['code' => 'x1', 'name' => 'X'], [], $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->update($role, ['name' => 'Y'], null, $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->delete($role, $manager))->toThrow(AuthorizationException::class);
    });

    it('journals permission changes with added and removed keys and bumps the version', function (): void {
        $role = Role::factory()->withPermissions(['pages.view', 'menu.view'])->create();

        $this->service->update($role, [], ['pages.view', 'products.view'], $this->admin);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::RolePermissionsChanged->value)->latest('id')->firstOrFail();

        expect($entry->data['added'])->toBe(['products.view'])
            ->and($entry->data['removed'])->toBe(['menu.view'])
            ->and($entry->user_id)->toBe($this->admin->id)
            ->and($role->refresh()->permissions_version)->toBeGreaterThan(1);
    });

    it('copies a role with its permissions', function (): void {
        $role = Role::factory()->withPermissions(['pages.view', 'menu.view'])->create(['name' => 'Контент']);

        $copy = $this->service->duplicate($role, $this->admin);

        expect($copy->id)->not->toBe($role->id)
            ->and($copy->is_system)->toBeFalse()
            ->and($copy->name)->toBe('Копия: Контент')
            ->and(app(PermissionService::class)->permissionsOfRole($copy))->toBe(['pages.view', 'menu.view']);
    });
});
