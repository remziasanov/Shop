<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Filament\Pages\SystemStatus;
use App\Models\Page;
use App\Models\User;
use App\Services\System\CheckResult;
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

it('warns about template legal pages in the doctor report and clears the warning after editing', function () {
    $find = fn () => collect(app(EnvironmentChecker::class)->run())->first(fn ($r) => $r->key === 'legal_pages');

    expect($find()->status)->toBe(CheckStatus::Warning)
        ->and($find()->hint)->toContain('Политика обработки персональных данных');

    $this->artisan('shop:doctor')->expectsOutputToContain('Юридические страницы');

    Page::query()->update(['content' => '<p>Своя редакция</p>']);

    expect($find()->status)->toBe(CheckStatus::Ok);
});

function setEnvironment(string $env): void
{
    app()->detectEnvironment(fn () => $env);
}

function envCheck(string $key): CheckResult
{
    $found = collect(app(EnvironmentChecker::class)->run())->firstWhere('key', $key);
    expect($found)->not->toBeNull();

    return $found;
}

it('flags an empty MAIL_FROM_ADDRESS as an error in production', function () {
    setEnvironment('production');
    config(['mail.from.address' => '']);

    expect(envCheck('mail_from')->status)->toBe(CheckStatus::Error)
        ->and(envCheck('mail_from')->hint)->not->toBeNull();

    config(['mail.from.address' => 'shop@example.ru']);
    expect(envCheck('mail_from')->status)->toBe(CheckStatus::Ok);
});

it('warns about log and array mailers in production only', function () {
    setEnvironment('local');
    config(['mail.default' => 'log']);
    expect(envCheck('mail_mailer')->status)->toBe(CheckStatus::Ok);

    setEnvironment('production');
    expect(envCheck('mail_mailer')->status)->toBe(CheckStatus::Warning);

    config(['mail.default' => 'smtp']);
    expect(envCheck('mail_mailer')->status)->toBe(CheckStatus::Ok);
});

it('errors on an empty SMTP host with the smtp mailer', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);
    expect(envCheck('mail_host')->status)->toBe(CheckStatus::Error);

    config(['mail.mailers.smtp.host' => 'smtp.example.ru']);
    expect(envCheck('mail_host')->status)->toBe(CheckStatus::Ok);

    config(['mail.default' => 'log', 'mail.mailers.smtp.host' => '']);
    expect(envCheck('mail_host')->status)->toBe(CheckStatus::Ok);
});

it('warns when APP_URL is not https in production and errors when invalid', function () {
    setEnvironment('local');
    config(['app.url' => 'http://example.ru']);
    expect(envCheck('app_url')->status)->toBe(CheckStatus::Ok);

    setEnvironment('production');
    expect(envCheck('app_url')->status)->toBe(CheckStatus::Warning);

    config(['app.url' => 'https://example.ru']);
    expect(envCheck('app_url')->status)->toBe(CheckStatus::Ok);

    config(['app.url' => 'not a url']);
    expect(envCheck('app_url')->status)->toBe(CheckStatus::Error);
});

it('warns about insecure session cookies in production', function () {
    setEnvironment('local');
    config(['session.secure' => false]);
    expect(envCheck('session_secure')->status)->toBe(CheckStatus::Ok);

    setEnvironment('production');
    expect(envCheck('session_secure')->status)->toBe(CheckStatus::Warning);

    config(['session.secure' => true]);
    expect(envCheck('session_secure')->status)->toBe(CheckStatus::Ok);
});

it('warns when the queue connection is not database', function () {
    config(['queue.default' => 'sync']);
    expect(envCheck('queue_connection')->status)->toBe(CheckStatus::Warning);

    config(['queue.default' => 'database']);
    expect(envCheck('queue_connection')->status)->toBe(CheckStatus::Ok);
});

it('errors on redis or memcached cache stores', function () {
    config(['cache.default' => 'redis']);
    expect(envCheck('cache_store')->status)->toBe(CheckStatus::Error);

    config(['cache.default' => 'memcached']);
    expect(envCheck('cache_store')->status)->toBe(CheckStatus::Error);

    config(['cache.default' => 'file']);
    expect(envCheck('cache_store')->status)->toBe(CheckStatus::Ok);
});

it('warns about debug log level in production', function () {
    setEnvironment('local');
    config(['logging.default' => 'daily', 'logging.channels.daily.level' => 'debug']);
    expect(envCheck('log_level')->status)->toBe(CheckStatus::Ok);

    setEnvironment('production');
    expect(envCheck('log_level')->status)->toBe(CheckStatus::Warning);

    foreach (['info', 'notice'] as $level) {
        config(['logging.channels.daily.level' => $level]);
        expect(envCheck('log_level')->status)->toBe(CheckStatus::Warning);
    }

    foreach (['warning', 'error'] as $level) {
        config(['logging.channels.daily.level' => $level]);
        expect(envCheck('log_level')->status)->toBe(CheckStatus::Ok);
    }
});

it('lists trusted hosts including SHOP_EXTRA_HOSTS', function () {
    config(['app.url' => 'https://example.ru', 'shop.extra_hosts' => ['login.beget.tech']]);

    expect(envCheck('trusted_hosts')->value)->toContain('example.ru', 'www.example.ru', 'login.beget.tech');
});
