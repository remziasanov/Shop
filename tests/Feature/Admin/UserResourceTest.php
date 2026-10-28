<?php

declare(strict_types=1);

use App\Enums\SystemRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\Users\AdminGuard;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('creates a user with a hashed password', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Мария',
            'email' => 'maria@example.com',
            'role_id' => roleId(SystemRole::Manager),
            'is_active' => true,
            'password' => 'long-enough-pass',
            'password_confirmation' => 'long-enough-pass',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect($user->role_id)->toBe(roleId(SystemRole::Manager))
        ->and(Hash::check('long-enough-pass', $user->password))->toBeTrue();
});

it('rejects short passwords, mismatches and duplicate emails', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'A',
            'email' => $this->admin->email,
            'role_id' => roleId(SystemRole::Manager),
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('create')
        ->assertHasFormErrors(['password', 'email']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'A',
            'email' => 'a@example.com',
            'role_id' => roleId(SystemRole::Manager),
            'password' => 'long-enough-pass',
            'password_confirmation' => 'different-pass-1',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('keeps the password when editing with an empty password field', function () {
    $user = User::factory()->manager()->create();
    $hash = $user->password;

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['name' => 'Новое имя', 'password' => '', 'password_confirmation' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->refresh()->name)->toBe('Новое имя')
        ->and($user->password)->toBe($hash);
});

it('lists users', function () {
    $other = User::factory()->manager()->create();

    Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$this->admin, $other]);
});

it('does not let an admin delete themselves', function () {
    expect($this->admin->can('delete', $this->admin))->toBeFalse();

    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->assertActionHidden(DeleteAction::class);
});

it('lets an admin delete another user', function () {
    $other = User::factory()->manager()->create();

    expect($this->admin->can('delete', $other))->toBeTrue();
});

it('denies managers every user policy ability', function () {
    $manager = User::factory()->manager()->create();

    expect($manager->can('viewAny', User::class))->toBeFalse()
        ->and($manager->can('create', User::class))->toBeFalse()
        ->and($manager->can('update', $this->admin))->toBeFalse()
        ->and($manager->can('delete', $this->admin))->toBeFalse();
});

it('locks role and activity fields of the own record', function () {
    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->assertFormFieldIsDisabled('role_id')
        ->assertFormFieldIsDisabled('is_active');
});

it('keeps role and activity editable for other users when another admin exists', function () {
    $other = User::factory()->manager()->create();

    Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
        ->assertFormFieldIsEnabled('role_id')
        ->assertFormFieldIsEnabled('is_active');
});

it('ignores tampered role and activity values for the own record', function () {
    User::factory()->admin()->create();

    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->set('data.role_id', roleId(SystemRole::Manager))
        ->set('data.is_active', false)
        ->call('save');

    expect($this->admin->refresh()->role_id)->toBe(roleId(SystemRole::Admin))
        ->and($this->admin->is_active)->toBeTrue();
});

it('rejects stripping own admin role or deactivating oneself on the server', function () {
    User::factory()->admin()->create();
    $guard = app(AdminGuard::class);

    expect(fn () => $guard->assertCanChange($this->admin, $this->admin, ['role_id' => roleId(SystemRole::Manager)]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $guard->assertCanChange($this->admin, $this->admin, ['is_active' => false]))
        ->toThrow(ValidationException::class);

    $guard->assertCanChange($this->admin, $this->admin, ['role_id' => roleId(SystemRole::Admin), 'is_active' => true]);
});

it('protects the last active admin from demotion, deactivation and deletion', function () {
    $guard = app(AdminGuard::class);
    $other = User::factory()->admin()->create();
    $actor = User::factory()->admin()->create();
    $manager = roleId(SystemRole::Manager);

    // Two other active admins exist: both can be changed by the actor.
    $guard->assertCanChange($other, $actor, ['role_id' => $manager]);
    expect($actor->can('delete', $other))->toBeTrue();

    // Leave one active admin: the other admin is demoted, then the actor is the last one.
    $this->admin->update(['is_active' => false]);
    $other->forceFill(['role_id' => $manager])->save();

    $last = User::factory()->manager()->create();
    expect($guard->isLastActiveAdmin($actor))->toBeTrue()
        ->and(fn () => $guard->assertCanChange($actor, $last, ['is_active' => false]))->toThrow(ValidationException::class)
        ->and(fn () => $guard->assertCanChange($actor, $last, ['role_id' => $manager]))->toThrow(ValidationException::class)
        ->and($guard->canDelete($actor, $last))->toBeFalse();

    $this->actingAs($actor);
    expect($actor->can('delete', $actor))->toBeFalse();
});

it('forbids deleting the last active admin through the policy even for another admin', function () {
    $inactiveAdmin = User::factory()->admin()->create(['is_active' => false]);
    $this->actingAs($inactiveAdmin);

    expect($inactiveAdmin->can('delete', $this->admin))->toBeFalse();
});

it('lets a manager-demoted admin be changed when another active admin remains', function () {
    $other = User::factory()->admin()->create();

    Livewire::test(EditUser::class, ['record' => $other->getRouteKey()])
        ->fillForm(['role_id' => roleId(SystemRole::Manager), 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->refresh()->role_id)->toBe(roleId(SystemRole::Manager))
        ->and($other->is_active)->toBeFalse();
});
