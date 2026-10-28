<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Services\Users\PermissionRegistry;
use App\Services\Users\PermissionService;
use App\Services\Users\RoleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RB-02, RB-04, RB-13: the single check point.
 */
describe('permission service', function (): void {
    it('lets the administrator role do everything, including unknown future permissions', function (): void {
        $admin = User::factory()->admin()->create();
        $service = app(PermissionService::class);

        expect($service->allows($admin, 'products.view'))->toBeTrue()
            ->and($service->allows($admin, 'future_module.explode'))->toBeTrue()
            ->and($service->isAdministrator($admin))->toBeTrue();
    });

    it('denies an inactive user and a user without a role', function (): void {
        $service = app(PermissionService::class);

        expect($service->allows(User::factory()->admin()->inactive()->create(), 'products.view'))->toBeFalse()
            ->and($service->allows(User::factory()->manager()->inactive()->create(), 'products.view'))->toBeFalse()
            ->and($service->allows(User::factory()->create(['role_id' => null]), 'products.view'))->toBeFalse();
    });

    it('grants exactly the stored permissions and ignores unknown rows', function (): void {
        $user = staffWith(['pages.view']);
        DB::table('role_permissions')->insert(['role_id' => $user->role_id, 'permission' => 'legacy.removed']);
        $service = app(PermissionService::class);
        $service->flush();

        expect($service->allows($user, 'pages.view'))->toBeTrue()
            ->and($service->allows($user, 'pages.update'))->toBeFalse()
            ->and($service->allows($user, 'legacy.removed'))->toBeFalse()
            ->and($service->permissionsOf($user))->toBe(['pages.view']);
    });

    it('caches the set under perm:role:{id}:{version} and applies a change at once', function (): void {
        $admin = User::factory()->admin()->create();
        $user = staffWith(['pages.view']);
        $role = Role::query()->findOrFail($user->role_id);
        $service = app(PermissionService::class);

        $before = $role->permissions_version;

        expect($service->allows($user, 'pages.view'))->toBeTrue()
            ->and(Cache::has('perm:role:'.$role->id.':'.$before))->toBeTrue();

        app(RoleService::class)->update($role, [], ['menu.view'], $admin);

        expect($role->refresh()->permissions_version)->toBe($before + 1)
            ->and($service->allows($user, 'pages.view'))->toBeFalse()
            ->and($service->allows($user, 'menu.view'))->toBeTrue()
            ->and(Cache::has('perm:role:'.$role->id.':'.($before + 1)))->toBeTrue();
    });

    it('reads a stale memo no longer after a request ends', function (): void {
        $user = staffWith(['pages.view']);
        $service = app(PermissionService::class);

        expect($service->allows($user, 'pages.view'))->toBeTrue();

        DB::table('role_permissions')->where('role_id', $user->role_id)->delete();
        DB::table('roles')->where('id', $user->role_id)->increment('permissions_version');
        $service->flush();

        expect($service->allows($user, 'pages.view'))->toBeFalse();
    });

    it('keeps the catalog consistent: unique, known manager defaults, sensitive markers', function (): void {
        $all = PermissionRegistry::all();

        expect($all)->toBe(array_values(array_unique($all)))
            ->and(array_diff(PermissionRegistry::managerDefaults(), $all))->toBe([])
            ->and(PermissionRegistry::isSensitive('users.view'))->toBeTrue()
            ->and(PermissionRegistry::isSensitive('settings.general.update'))->toBeTrue()
            ->and(PermissionRegistry::isSensitive('products.view'))->toBeFalse()
            ->and($all)->toContain('settings.age_gate.update', 'prices.bulk', 'imports.full_sync', 'orders.delete', 'html_block.edit', 'system.audit_log.view');
    });
});
