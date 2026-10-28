<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Exceptions\StaffAccessException;
use App\Models\AdminAuditEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\AdminGuard;
use App\Services\Users\StaffAccountService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->service = app(StaffAccountService::class);
});

/**
 * RB-07, RB-08, RB-10, RB-12.
 */
describe('staff account service', function (): void {
    it('creates a user and journals it without personal data', function (): void {
        $user = $this->service->create([
            'name' => 'Мария', 'email' => 'maria@example.com', 'password' => 'long-enough-pass',
            'role_id' => roleId(SystemRole::Manager), 'is_active' => true,
        ], $this->admin);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::UserCreated->value)->firstOrFail();

        expect($user->role_id)->toBe(roleId(SystemRole::Manager))
            ->and($entry->subject_id)->toBe($user->id)
            ->and(json_encode($entry->data))->not->toContain('maria@example.com')->not->toContain('Мария');
    });

    it('protects the last active administrator from demotion, deactivation and deletion', function (): void {
        $second = User::factory()->admin()->create();

        // Deactivating one of two administrators is fine ...
        $this->service->update($second, ['is_active' => false], $this->admin);

        $guard = app(AdminGuard::class);

        // ... after that nobody can lock the remaining one out, not even the other admin account.
        expect($guard->isLastActiveAdmin($this->admin))->toBeTrue()
            ->and(fn () => $this->service->update($this->admin, ['is_active' => false], $this->admin))->toThrow(ValidationException::class)
            ->and(fn () => $this->service->update($this->admin, ['role_id' => roleId(SystemRole::Manager)], $this->admin))->toThrow(ValidationException::class)
            ->and(fn () => $this->service->delete($this->admin, $this->admin))->toThrow(StaffAccessException::class)
            ->and(fn () => $guard->assertCanChange($this->admin, $second, ['is_active' => false]))->toThrow(ValidationException::class)
            ->and($guard->canDelete($this->admin, $second))->toBeFalse()
            ->and($this->admin->refresh()->is_active)->toBeTrue();
    });

    it('lets one of two administrators be deactivated and journals it', function (): void {
        $second = User::factory()->admin()->create();

        $this->service->update($second, ['is_active' => false], $this->admin);

        expect($second->refresh()->is_active)->toBeFalse()
            ->and(AdminAuditEntry::query()->where('action', AuditAction::UserDeactivated->value)->where('subject_id', $second->id)->exists())->toBeTrue();
    });

    it('never lets an administrator delete or demote themselves', function (): void {
        User::factory()->admin()->create();

        expect(fn () => $this->service->delete($this->admin, $this->admin))->toThrow(StaffAccessException::class)
            ->and(fn () => $this->service->update($this->admin, ['role_id' => roleId(SystemRole::Manager)], $this->admin))->toThrow(ValidationException::class);
    });

    it('journals a role change', function (): void {
        $user = User::factory()->manager()->create();

        $this->service->update($user, ['role_id' => roleId(SystemRole::Admin)], $this->admin);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::UserRoleChanged->value)->firstOrFail();

        // MySQL stores JSON with its own key order, so compare without order.
        expect($entry->data)->toEqual(['from' => 'manager', 'to' => 'admin']);
    });

    it('does not let staff hand out a role with rights they lack', function (): void {
        $actor = staffWith(['users.view', 'users.create', 'users.update', 'products.view']);
        $weak = Role::factory()->withPermissions(['products.view'])->create();
        $strong = Role::factory()->withPermissions(['products.view', 'orders.delete'])->create();
        $payload = ['name' => 'N', 'email' => 'n@example.com', 'password' => 'long-enough-pass', 'is_active' => true];

        expect(fn () => $this->service->create($payload + ['role_id' => $strong->id], $actor))->toThrow(StaffAccessException::class)
            ->and(fn () => $this->service->create($payload + ['role_id' => roleId(SystemRole::Admin)], $actor))->toThrow(StaffAccessException::class);

        $created = $this->service->create($payload + ['role_id' => $weak->id], $actor);

        expect($created->role_id)->toBe($weak->id);

        $target = User::factory()->withRole($weak)->create();

        expect(fn () => $this->service->update($target, ['role_id' => $strong->id], $actor))->toThrow(StaffAccessException::class);
    });

    it('does not let staff change the account of a user with more rights', function (): void {
        $actor = staffWith(['users.view', 'users.update', 'users.delete']);

        expect(fn () => $this->service->update($this->admin, ['password' => 'takeover-pass-1'], $actor))->toThrow(StaffAccessException::class)
            ->and(fn () => $this->service->delete($this->admin, $actor))->toThrow(StaffAccessException::class)
            ->and($actor->can('update', $this->admin))->toBeFalse()
            ->and($actor->can('delete', $this->admin))->toBeFalse();
    });

    it('requires users.* permissions inside the service', function (): void {
        $manager = User::factory()->manager()->create();
        $target = User::factory()->manager()->create();

        expect(fn () => $this->service->update($target, ['name' => 'X'], $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->delete($target, $manager))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->service->create(['name' => 'N', 'email' => 'q@example.com', 'password' => 'long-enough-pass', 'role_id' => roleId(SystemRole::Manager)], $manager))
            ->toThrow(AuthorizationException::class);
    });
});
