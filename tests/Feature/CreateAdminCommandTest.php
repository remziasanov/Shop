<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\SystemRole;
use App\Models\AdminAuditEntry;
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

    expect($user->role->code)->toBe(SystemRole::Admin->value)
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

it('writes the creation to the action journal without personal data (RB-12)', function () {
    $this->artisan('shop:create-admin', [
        '--name' => 'Админ',
        '--email' => 'boss@example.com',
        '--password' => 'correct-horse-battery',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'boss@example.com')->firstOrFail();
    $entry = AdminAuditEntry::query()->where('action', AuditAction::UserCreated->value)->firstOrFail();

    expect($entry->subject_type)->toBe('users')
        ->and($entry->subject_id)->toBe($user->id)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->data)->toMatchArray(['role' => SystemRole::Admin->value, 'source' => 'console'])
        ->and(json_encode($entry->data))->not->toContain('boss@example.com')->not->toContain('Админ');
});

it('writes nothing to the journal when the creation is refused', function () {
    $this->artisan('shop:create-admin', ['--email' => 'bad', '--no-interaction' => true])->assertFailed();

    expect(AdminAuditEntry::query()->count())->toBe(0);
});
