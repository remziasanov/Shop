<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Filament\Pages\SystemStatus;
use App\Models\User;
use App\Services\System\EnvironmentChecker;
use App\Services\System\Heartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    @unlink(app(Heartbeat::class)->path());
});

afterEach(function () {
    Carbon::setTestNow();
    @unlink(app(Heartbeat::class)->path());
});

it('writes the heartbeat via the command', function () {
    Carbon::setTestNow('2026-10-26 12:00:00');

    expect(app(Heartbeat::class)->lastRun())->toBeNull();

    $this->artisan('shop:heartbeat')->assertSuccessful();

    $last = app(Heartbeat::class)->lastRun();
    expect($last)->not->toBeNull()
        ->and($last->timestamp)->toBe(Carbon::now()->timestamp);
});

it('detects a stale heartbeat', function () {
    $heartbeat = app(Heartbeat::class);
    expect($heartbeat->isStale())->toBeTrue();

    Carbon::setTestNow('2026-10-26 12:00:00');
    $heartbeat->touch();
    expect($heartbeat->isStale())->toBeFalse();

    Carbon::setTestNow('2026-10-26 12:04:00');
    expect($heartbeat->isStale())->toBeFalse();

    Carbon::setTestNow('2026-10-26 12:06:00');
    expect($heartbeat->isStale())->toBeTrue()
        ->and($heartbeat->isStale(10))->toBeFalse();
});

it('schedules the heartbeat every minute', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('shop:heartbeat')->assertSuccessful();
});

it('returns statuses for every check', function () {
    $results = app(EnvironmentChecker::class)->run();
    $keys = array_map(fn ($r) => $r->key, $results);

    expect($results)->not->toBeEmpty()
        ->and($keys)->toContain('php_version', 'ext_pdo_mysql', 'database', 'scheduler', 'photos_size')
        ->and(collect($results)->first(fn ($r) => $r->key === 'php_version')->status)->toBe(CheckStatus::Ok)
        ->and(collect($results)->first(fn ($r) => $r->key === 'scheduler')->status)->toBe(CheckStatus::Warning);
});

it('turns a failing check into an error without throwing', function () {
    DB::shouldReceive('connection')->andThrow(new RuntimeException('boom'));

    $results = app(EnvironmentChecker::class)->run();
    $database = collect($results)->first(fn ($r) => $r->key === 'database');

    expect($database->status)->toBe(CheckStatus::Error)
        ->and($database->value)->toBe('boom');
});

it('prints the doctor table', function () {
    $this->artisan('shop:doctor')
        ->expectsOutputToContain('Проверка')
        ->expectsOutputToContain('Версия PHP');
});

it('outputs valid JSON from doctor', function () {
    Artisan::call('shop:doctor', ['--json' => true]);
    $output = Artisan::output();
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($data)->toBeArray()->not->toBeEmpty()
        ->and($data[0])->toHaveKeys(['key', 'label', 'status', 'value', 'hint']);
});

it('exits with 1 when a check fails', function () {
    config(['app.key' => '']);

    $this->artisan('shop:doctor --json')->assertExitCode(1);
});

it('shows the system page to admins only', function () {
    $this->actingAs(User::factory()->admin()->create());
    $this->get(SystemStatus::getUrl())->assertOk()->assertSee('Версия PHP');
});

it('forbids the system page for managers', function () {
    $this->actingAs(User::factory()->create(['role' => 'manager']));
    $this->get(SystemStatus::getUrl())->assertForbidden();
});
