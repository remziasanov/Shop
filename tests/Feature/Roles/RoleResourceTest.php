<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\AdminAuditEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\System\EnvironmentChecker;
use App\Services\Users\AuditLogger;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/**
 * RB-09, RB-10, RB-12, RB-14.
 */
describe('roles resource', function (): void {
    it('lists roles with user and permission counts', function (): void {
        $role = Role::factory()->withPermissions(['pages.view', 'pages.update'])->create(['name' => 'Контент']);
        User::factory()->withRole($role)->create();

        Livewire::test(ListRoles::class)
            ->assertCanSeeTableRecords(Role::query()->get())
            ->assertTableColumnStateSet('users_count', 1, $role)
            ->assertTableColumnStateSet('permissions_count', '2', $role);
    });

    it('creates a role from the permission matrix', function (): void {
        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Контент-менеджер',
                'code' => 'content-manager',
                'matrix' => ['pages' => ['pages.view', 'pages.update'], 'products' => ['products.view']],
                'special' => ['html_block' => ['html_block.edit']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::query()->where('code', 'content-manager')->firstOrFail();

        expect($role->storedPermissions())->toBe(['html_block.edit', 'pages.update', 'pages.view', 'products.view'])
            ->and($role->is_system)->toBeFalse();
    });

    it('validates the code', function (): void {
        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'X', 'code' => 'Bad Code'])
            ->call('create')
            ->assertHasFormErrors(['code']);

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'X', 'code' => 'manager'])
            ->call('create')
            ->assertHasFormErrors(['code']);
    });

    it('shows the stored permissions when editing and saves changes', function (): void {
        $role = Role::factory()->withPermissions(['pages.view'])->create();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormSet(['matrix.pages' => ['pages.view']])
            ->fillForm(['matrix' => ['pages' => ['pages.view', 'pages.update']]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($role->refresh()->storedPermissions())->toBe(['pages.update', 'pages.view']);
    });

    it('does not offer a matrix for the Administrator role and keeps its code locked', function (): void {
        $adminRole = Role::query()->where('code', 'admin')->firstOrFail();
        // An administrator may not edit their own role (RB-08), so another role holder opens it.
        $this->actingAs(staffWith(['roles.view', 'roles.update']));

        Livewire::test(EditRole::class, ['record' => $adminRole->getRouteKey()])->assertFormFieldIsDisabled('code');

        // The permission sections are not rendered for the Administrator role (it stores nothing, RB-02).
        $this->get(RoleResource::getUrl('edit', ['record' => $adminRole]))
            ->assertOk()
            ->assertSee(__('roles.form.administrator_title'))
            ->assertDontSee(__('roles.form.special'));
    });

    it('refuses to delete a system role from the table', function (): void {
        $manager = Role::query()->where('code', 'manager')->firstOrFail();

        Livewire::test(ListRoles::class)
            ->assertTableActionHidden('delete', $manager);

        expect(Role::query()->whereKey($manager->id)->exists())->toBeTrue();
    });

    it('refuses to delete a role with users and says why', function (): void {
        $role = Role::factory()->create();
        User::factory()->withRole($role)->create(['name' => 'Пётр']);

        Livewire::test(ListRoles::class)
            ->callAction(TestAction::make('delete')->table($role))
            ->assertNotified();

        expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
    });

    it('copies a role', function (): void {
        $role = Role::factory()->withPermissions(['pages.view'])->create(['name' => 'Контент']);

        Livewire::test(ListRoles::class)->callAction(TestAction::make('duplicate')->table($role));

        expect(Role::query()->where('name', 'Копия: Контент')->exists())->toBeTrue();
    });

    it('is forbidden without roles.view', function (): void {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(ListRoles::class)->assertForbidden();
    });
});

describe('users resource with roles', function (): void {
    it('offers only roles the actor may assign (RB-08) and rejects others on the server', function (): void {
        $weak = Role::factory()->withPermissions(['products.view'])->create(['name' => 'Слабая']);
        $strong = Role::factory()->withPermissions(['products.view', 'orders.delete'])->create(['name' => 'Сильная']);
        $this->actingAs(staffWith(['users.view', 'users.create', 'products.view']));

        $options = UserResource::roleOptions(null);

        expect($options)->toHaveKey($weak->id)->not->toHaveKey($strong->id);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'N', 'email' => 'n@example.com', 'role_id' => $strong->id, 'is_active' => true,
                'password' => 'long-enough-pass', 'password_confirmation' => 'long-enough-pass',
            ])
            ->call('create');

        expect(User::query()->where('email', 'n@example.com')->exists())->toBeFalse();
    });
});

describe('audit log (RB-12)', function (): void {
    it('shows the journal to staff with system.audit_log.view only', function (): void {
        app(AuditLogger::class)->record(AuditAction::RoleCreated, $this->admin, Role::query()->first(), ['code' => 'x']);

        Livewire::test(ListAuditLog::class)
            ->assertCanSeeTableRecords(AdminAuditEntry::query()->get());

        $this->actingAs(User::factory()->manager()->create());
        Livewire::test(ListAuditLog::class)->assertForbidden();
    });

    it('purges entries older than the retention period', function (): void {
        $logger = app(AuditLogger::class);
        $old = $logger->record(AuditAction::RoleCreated, $this->admin);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();
        $fresh = $logger->record(AuditAction::RoleCreated, $this->admin);

        expect($logger->purgeOlderThan())->toBe(1)
            ->and(AdminAuditEntry::query()->whereKey($fresh->id)->exists())->toBeTrue();
    });
});

describe('shop:doctor staff check (RB-14)', function (): void {
    it('reports ok with an active administrator and roles for everyone', function (): void {
        $result = collect(app(EnvironmentChecker::class)->run())->firstWhere('key', 'staff_roles');

        expect($result->status->value)->toBe('ok');
    });

    it('fails without an active administrator or with a user without role', function (): void {
        $this->admin->forceFill(['is_active' => false])->save();
        $result = collect(app(EnvironmentChecker::class)->run())->firstWhere('key', 'staff_roles');
        expect($result->status->value)->toBe('error');

        $this->admin->forceFill(['is_active' => true])->save();
        User::factory()->create(['role_id' => null]);
        $result = collect(app(EnvironmentChecker::class)->run())->firstWhere('key', 'staff_roles');
        expect($result->status->value)->toBe('error');
    });
});
