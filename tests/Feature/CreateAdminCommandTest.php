<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates an administrator non-interactively', function () {
    $this->artisan('shop:create-admin', [
        '--name' => 'Админ',
        '--email' => 'boss@example.com',
        '--password' => 'correct-horse-battery',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'boss@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Admin)
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('correct-horse-battery', $user->password))->toBeTrue();
});

it('rejects a short password', function () {
    $this->artisan('shop:create-admin', [
        '--name' => 'Админ',
        '--email' => 'boss@example.com',
        '--password' => 'short',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(0);
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('shop:create-admin', [
        '--name' => 'Админ',
        '--email' => 'boss@example.com',
        '--password' => 'correct-horse-battery',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(1);
});

it('rejects an invalid email and missing values', function () {
    $this->artisan('shop:create-admin', [
        '--email' => 'not-an-email',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(0);
});
