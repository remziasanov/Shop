<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Enums\OrderStatus;
use App\Filament\Pages\Settings\ManageTelegramSettings;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\System\CheckResult;
use App\Services\System\EnvironmentChecker;
use App\Services\Telegram\TelegramPruner;
use App\Services\Users\PermissionRegistry;
use App\Settings\TelegramSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * TG-12: shop:telegram-prune and the doctor checks. Permissions settings.telegram.* (TZ 32.2).
 */
describe('shop:telegram-prune', function (): void {
    it('deletes updates older than 7 days and keeps newer ones', function (): void {
        TelegramUpdate::factory()->create(['update_id' => 1, 'received_at' => now()->subDays(8)]);
        TelegramUpdate::factory()->create(['update_id' => 2, 'received_at' => now()->subDays(6)]);

        $this->artisan('shop:telegram-prune')->assertSuccessful();

        expect(TelegramUpdate::query()->pluck('update_id')->all())->toBe([2]);
    });

    it('deletes used and expired link tokens and keeps usable ones', function (): void {
        $usable = TelegramLinkToken::factory()->create();
        TelegramLinkToken::factory()->used()->create();
        TelegramLinkToken::factory()->expired()->create();

        $this->artisan('shop:telegram-prune')->assertSuccessful();

        expect(TelegramLinkToken::query()->pluck('id')->all())->toBe([$usable->id]);
    });

    it('deletes accounts without customer and staff that were silent for 12 months and have no unfinished request', function (): void {
        $old = now()->subMonths(13);
        $stale = TelegramAccount::factory()->create(['last_seen_at' => $old]);
        $staleNeverSeen = TelegramAccount::factory()->create(['last_seen_at' => null, 'created_at' => $old]);
        $recent = TelegramAccount::factory()->create(['last_seen_at' => now()->subMonths(2)]);
        $linkedCustomer = TelegramAccount::factory()->create(['last_seen_at' => $old, 'customer_id' => Customer::factory()->create()->id]);
        $linkedStaff = TelegramAccount::factory()->create(['last_seen_at' => $old, 'user_id' => User::factory()->manager()->create()->id]);
        $busy = TelegramAccount::factory()->create(['last_seen_at' => $old]);
        Order::factory()->status(OrderStatus::New)->create(['telegram_account_id' => $busy->id]);
        $finished = TelegramAccount::factory()->create(['last_seen_at' => $old]);
        $finishedOrder = Order::factory()->status(OrderStatus::Completed)->create(['telegram_account_id' => $finished->id]);

        $this->artisan('shop:telegram-prune')->assertSuccessful();

        $left = TelegramAccount::query()->pluck('id')->all();
        expect($left)->toContain($recent->id, $linkedCustomer->id, $linkedStaff->id, $busy->id)
            ->and($left)->not->toContain($stale->id, $staleNeverSeen->id, $finished->id);
        // The finished request stays; its field is nulled by ON DELETE SET NULL.
        expect($finishedOrder->refresh()->telegram_account_id)->toBeNull();
    });

    it('does not fail while telegram_messages does not exist yet (stage 18)', function (): void {
        expect(app(TelegramPruner::class)->run()['messages'])->toBe(0);
    });

    it('is registered daily in the scheduler', function (): void {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'shop:telegram-prune'));

        expect($event->expression)->toBe('15 4 * * *');
    });
});

describe('doctor (TG-12)', function (): void {
    function tgCheck(string $key): CheckResult
    {
        return collect(app(EnvironmentChecker::class)->run())->firstWhere('key', $key);
    }

    beforeEach(function (): void {
        Cache::flush();
    });

    it('reports nothing wrong while the bot is not connected (NF-63)', function (): void {
        Http::fake();

        foreach (['telegram_https', 'telegram_webhook', 'telegram_pending', 'telegram_errors', 'telegram_startapp', 'telegram_work_chat'] as $key) {
            expect(tgCheck($key)->status)->toBe(CheckStatus::Ok);
        }

        Http::assertNothingSent();
    });

    it('is green for a connected bot with the webhook on the current APP_URL', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot(['startapp_verified_at' => now()->toIso8601String()]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => 'https://shop.example.com/telegram/webhook', 'pending_update_count' => 2]])]);

        foreach (['telegram_https', 'telegram_webhook', 'telegram_pending', 'telegram_errors', 'telegram_startapp', 'telegram_work_chat'] as $key) {
            expect(tgCheck($key)->status)->toBe(CheckStatus::Ok, $key);
        }
    });

    it('fails for a webhook on another address or an http APP_URL', function (): void {
        config(['app.url' => 'http://shop.example.com']);
        connectTelegramBot();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => 'https://old.example.com/telegram/webhook']])]);

        expect(tgCheck('telegram_https')->status)->toBe(CheckStatus::Error)
            ->and(tgCheck('telegram_webhook')->status)->toBe(CheckStatus::Error)
            ->and(tgCheck('telegram_webhook')->value)->toContain('old.example.com');
    });

    it('fails when no webhook is set', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => '']])]);

        expect(tgCheck('telegram_webhook')->status)->toBe(CheckStatus::Error);
    });

    it('warns on 100 or more pending updates, a webhook error in the last 24 hours and missing startapp links', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
            'url' => 'https://shop.example.com/telegram/webhook',
            'pending_update_count' => 100,
            'last_error_date' => now()->subHours(2)->getTimestamp(),
            'last_error_message' => 'Wrong response from the webhook: 500',
        ]])]);

        expect(tgCheck('telegram_pending')->status)->toBe(CheckStatus::Warning)
            ->and(tgCheck('telegram_errors')->status)->toBe(CheckStatus::Warning)
            ->and(tgCheck('telegram_errors')->value)->toContain('500')
            ->and(tgCheck('telegram_startapp')->status)->toBe(CheckStatus::Warning);
    });

    it('ignores a webhook error older than 24 hours', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => 'https://shop.example.com/telegram/webhook', 'last_error_date' => now()->subDays(2)->getTimestamp(), 'last_error_message' => 'old']])]);

        expect(tgCheck('telegram_errors')->status)->toBe(CheckStatus::Ok);
    });

    it('warns instead of failing when Telegram does not answer', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'boom'], 500)]);

        expect(tgCheck('telegram_webhook')->status)->toBe(CheckStatus::Warning);
    });

    it('shows a work chat error as an error (TG-10)', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->work_chat_error = 'Forbidden: bot was kicked';
        $settings->save();

        expect(tgCheck('telegram_work_chat')->status)->toBe(CheckStatus::Error)->and(tgCheck('telegram_work_chat')->value)->toContain('kicked');
    });

    it('appears in shop:doctor output', function (): void {
        User::factory()->admin()->create();
        Artisan::call('shop:doctor', ['--json' => true]);

        expect(array_column(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR), 'key'))->toContain('telegram_webhook', 'telegram_work_chat');
    });
});

describe('permissions (TZ 32.2)', function (): void {
    it('has settings.telegram.view and .update, and the Manager has neither by default', function (): void {
        expect(PermissionRegistry::exists('settings.telegram.view'))->toBeTrue()
            ->and(PermissionRegistry::exists('settings.telegram.update'))->toBeTrue()
            ->and(PermissionRegistry::managerDefaults())->not->toContain('settings.telegram.view', 'settings.telegram.update');
    });

    it('opens the page by view and edits by update only', function (): void {
        $viewer = staffWith(['settings.telegram.view']);
        $editor = staffWith(['settings.telegram.update']);
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager);
        expect(ManageTelegramSettings::canAccess())->toBeFalse();

        $this->actingAs($viewer);
        expect(ManageTelegramSettings::canAccess())->toBeTrue()
            ->and((new ManageTelegramSettings)->canEdit())->toBeFalse();

        $this->actingAs($editor);
        expect((new ManageTelegramSettings)->canEdit())->toBeTrue();
    });
});
