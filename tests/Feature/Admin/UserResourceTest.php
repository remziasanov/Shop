<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Hash;
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
            'role' => 'manager',
            'is_active' => true,
            'password' => 'long-enough-pass',
            'password_confirmation' => 'long-enough-pass',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'maria@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Manager)
        ->and(Hash::check('long-enough-pass', $user->password))->toBeTrue();
});

it('rejects short passwords, mismatches and duplicate emails', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'A',
            'email' => $this->admin->email,
            'role' => 'manager',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('create')
        ->assertHasFormErrors(['password', 'email']);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'A',
            'email' => 'a@example.com',
            'role' => 'manager',
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
