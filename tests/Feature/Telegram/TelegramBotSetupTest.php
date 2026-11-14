<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageTelegramSettings;
use App\Models\Customer;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramBotSetup;
use App\Settings\TelegramSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * TG-01…TG-03, TG-11: connecting the bot, the state block, disconnecting, the settings page.
 */
function fakeBotApi(array $overrides = []): void
{
    Http::fake($overrides + [
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 777, 'is_bot' => true, 'username' => 'shop_bot']]),
        'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://shop.example.com/telegram/webhook', 'pending_update_count' => 3]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);
}

beforeEach(function (): void {
    $this->withoutVite();
    config(['app.url' => 'https://shop.example.com']);
    $settings = app(TelegramSettings::class);
    $settings->bot_token = '777:SAVED-token';
    $settings->save();
});

describe('connect (TG-02)', function (): void {
    it('runs getMe, setWebhook, setMyCommands and setChatMenuButton with the right payloads', function (): void {
        fakeBotApi();

        $steps = app(TelegramBotSetup::class)->connect();

        expect(array_column($steps, 'key'))->toBe(['getMe', 'setWebhook', 'setMyCommands', 'setChatMenuButton'])
            ->and(array_unique(array_column($steps, 'ok')))->toBe([true]);

        $settings = app(TelegramSettings::class);
        expect($settings->bot_username)->toBe('shop_bot')->and($settings->bot_id)->toBe(777)->and($settings->connected_at)->not->toBeNull()
            ->and($settings->webhook_secret)->toMatch('/^[0-9a-f]{64}$/');

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://shop.example.com/telegram/webhook'
            && $r['secret_token'] === $settings->webhook_secret
            && $r['allowed_updates'] === ['message', 'callback_query', 'my_chat_member']
            && $r['max_connections'] === 5);
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/setMyCommands')
            && array_column($r['commands'], 'command') === ['start', 'orders', 'help', 'stop']
            && $r['commands'][0]['description'] === __('telegram.commands.start'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/setChatMenuButton')
            && $r['menu_button'] === ['type' => 'web_app', 'text' => 'Магазин', 'web_app' => ['url' => 'https://shop.example.com/tg']]);
    });

    it('refuses to connect without https and calls nothing', function (): void {
        config(['app.url' => 'http://shop.example.com']);
        fakeBotApi();
        $setup = app(TelegramBotSetup::class);

        $steps = $setup->connect();

        expect($setup->canConnect())->toBeFalse()->and($steps)->toHaveCount(1)->and($steps[0]['ok'])->toBeFalse()->and($steps[0]['detail'])->toBe(__('telegram.admin.https_required'));
        Http::assertNothingSent();
    });

    it('stops at the first failed step, shows the error without the token and does not mark the bot connected', function (): void {
        fakeBotApi(['api.telegram.org/*/setWebhook' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: bad webhook: HTTPS url must be provided for webhook (777:SAVED-token)'], 400)]);

        $steps = app(TelegramBotSetup::class)->connect();

        expect(array_column($steps, 'ok'))->toBe([true, false])->and($steps[1]['detail'])->not->toContain('SAVED-token');
        expect(app(TelegramSettings::class)->connected_at)->toBeNull()->and(app(TelegramSettings::class)->webhook_secret)->toBeNull();
    });

    it('saves a new token only after getMe accepted it', function (): void {
        fakeBotApi(['api.telegram.org/*/getMe' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

        $steps = app(TelegramBotSetup::class)->connect('999:BAD');

        expect($steps[0]['ok'])->toBeFalse()->and(app(TelegramSettings::class)->bot_token)->toBe('777:SAVED-token');
    });

    it('rotates the webhook secret on every connect', function (): void {
        fakeBotApi();
        $setup = app(TelegramBotSetup::class);

        $setup->connect();
        $first = app(TelegramSettings::class)->webhook_secret;
        $setup->connect();

        expect(app(TelegramSettings::class)->webhook_secret)->not->toBe($first);
    });
});

describe('token change (TG-11)', function (): void {
    it('keeps the links and the chat state when the token changes within the same bot', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->bot_id = 777;
        $settings->save();
        $account = TelegramAccount::factory()->chatStarted()->create(['customer_id' => Customer::factory()->create()->id]);
        fakeBotApi();

        $steps = app(TelegramBotSetup::class)->connect('777:NEW-token');

        expect(array_column($steps, 'key'))->not->toContain('bot_changed')
            ->and(app(TelegramSettings::class)->bot_token)->toBe('777:NEW-token')
            ->and($account->refresh()->chat_started_at)->not->toBeNull()->and($account->customer_id)->not->toBeNull();
    });

    it('resets chat_started_at of all accounts when the bot itself changes, keeping the links', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->bot_id = 111;
        $settings->save();
        $customer = Customer::factory()->create();
        $account = TelegramAccount::factory()->chatStarted()->create(['customer_id' => $customer->id]);
        fakeBotApi();

        $steps = app(TelegramBotSetup::class)->connect('777:NEW-token');

        expect(array_column($steps, 'key'))->toContain('bot_changed')
            ->and($account->refresh()->chat_started_at)->toBeNull()->and($account->customer_id)->toBe($customer->id);
    });

    it('shows the «Нажмите /start» hint in the cabinet after the reset', function (): void {
        connectTelegramBot();
        $customer = Customer::factory()->create();
        TelegramAccount::factory()->create(['customer_id' => $customer->id, 'chat_started_at' => null]);
        signInCustomer($this, $customer);

        $this->get(route('customer.notifications'))->assertOk()->assertSee(__('telegram.cabinet.start_hint'));
    });
});

describe('state, disconnect, startapp', function (): void {
    it('reads getWebhookInfo for the state block', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => 'https://x/hook', 'pending_update_count' => 5, 'last_error_message' => 'Connection timed out', 'last_error_date' => 1_700_000_000]])]);

        $state = app(TelegramBotSetup::class)->webhookState();

        expect($state['url'])->toBe('https://x/hook')->and($state['pending'])->toBe(5)->and($state['last_error'])->toBe('Connection timed out')->and($state['last_error_at']->timestamp)->toBe(1_700_000_000);
    });

    it('deletes the webhook and restores the default menu button, keeping token and links', function (): void {
        fakeBotApi();
        connectTelegramBot();
        $account = TelegramAccount::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $steps = app(TelegramBotSetup::class)->disconnect();

        expect(array_column($steps, 'key'))->toBe(['deleteWebhook', 'setChatMenuButton']);
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/deleteWebhook'));
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/setChatMenuButton') && $r['menu_button'] === ['type' => 'default']);
        expect(app(TelegramBotSetup::class)->isConnected())->toBeFalse()
            ->and(app(TelegramSettings::class)->bot_token)->not->toBeNull()
            ->and($account->refresh()->customer_id)->not->toBeNull();
    });

    it('records startapp_verified_at and links to t.me/{bot}?startapp=check (TG-03)', function (): void {
        connectTelegramBot();
        $setup = app(TelegramBotSetup::class);

        expect($setup->startappCheckUrl())->toBe('https://t.me/test_shop_bot?startapp=check')->and($setup->startappVerified())->toBeFalse();

        $setup->markStartappVerified();

        expect($setup->startappVerified())->toBeTrue()->and(app(TelegramSettings::class)->startapp_verified_at)->not->toBeNull();
    });
});

describe('settings page (TG-01)', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('never sends the token or the webhook secret to the browser', function (): void {
        connectTelegramBot();

        $component = Livewire::test(ManageTelegramSettings::class);

        expect($component->html())->not->toContain('TEST-token_abc')->not->toContain(TG_SECRET)
            ->and(json_encode($component->snapshot))->not->toContain('TEST-token_abc')->not->toContain(TG_SECRET);
        $component->assertSet('data.bot_token', null)->assertFormFieldHidden('bot_token')->assertFormFieldExists('change_token');
    });

    it('keeps the token on an empty save and saves the editable fields', function (): void {
        connectTelegramBot();

        Livewire::test(ManageTelegramSettings::class)
            ->fillForm(['work_chat_id' => ' -100500 ', 'menu_button_text' => 'Каталог', 'miniapp_colors' => 'telegram', 'initdata_ttl_customer' => 1800])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(TelegramSettings::class);
        expect($settings->bot_token)->toBe('123456:TEST-token_abc')
            ->and($settings->webhook_secret)->toBe(TG_SECRET)
            ->and($settings->work_chat_id)->toBe('-100500')
            ->and($settings->menu_button_text)->toBe('Каталог')
            ->and($settings->miniapp_colors)->toBe('telegram')
            ->and($settings->initdata_ttl_customer)->toBe(1800);
    });

    it('does not overwrite service fields from a stale form', function (): void {
        connectTelegramBot();
        $component = Livewire::test(ManageTelegramSettings::class);

        $settings = app(TelegramSettings::class);
        $settings->work_chat_error = 'Chat not found';
        $settings->bot_username = 'newer_bot';
        $settings->save();

        $component->fillForm(['work_chat_id' => '-5'])->call('save');

        expect(app(TelegramSettings::class)->work_chat_error)->toBe('Chat not found')->and(app(TelegramSettings::class)->bot_username)->toBe('newer_bot');
    });

    it('«Подключить бота» with a typed token connects and does not echo the token back', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        fakeBotApi();

        $component = Livewire::test(ManageTelegramSettings::class)
            ->fillForm(['bot_token' => '888:TYPED-secret'])
            ->callAction(TestAction::make('connectBot')->schemaComponent('connectionActions', schema: 'form'));

        expect(app(TelegramSettings::class)->bot_token)->toBe('888:TYPED-secret')
            ->and(app(TelegramBotSetup::class)->isConnected())->toBeTrue()
            ->and($component->html())->not->toContain('TYPED-secret')->toContain('data-telegram-steps');
        $component->assertSet('data.bot_token', null);
    });

    it('explains instead of connecting when APP_URL is not https', function (): void {
        config(['app.url' => 'http://shop.example.com']);
        Http::fake();

        $component = Livewire::test(ManageTelegramSettings::class);

        expect($component->html())->toContain('data-telegram-https');
        Http::assertNothingSent();
    });

    it('shows the webhook state after «Проверить» and a work chat test message', function (): void {
        connectTelegramBot(['work_chat_id' => '-1001']);
        fakeBotApi();

        Livewire::test(ManageTelegramSettings::class)
            ->callAction(TestAction::make('checkState')->schemaComponent('connectionActions', schema: 'form'))
            ->assertSee('https://shop.example.com/telegram/webhook')
            ->callAction(TestAction::make('sendWorkChatTest')->schemaComponent('connectionActions', schema: 'form'))
            ->assertNotified(__('notifications.test.telegram_sent'));

        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '-1001');
    });

    it('changing the token on save re-sets the webhook (TG-11)', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot();
        fakeBotApi();

        Livewire::test(ManageTelegramSettings::class)
            ->fillForm(['change_token' => true, 'bot_token' => '123456:ROTATED'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(TelegramSettings::class)->bot_token)->toBe('123456:ROTATED');
        Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/setWebhook'));
    });

    it('shows the work chat error and the startapp mark', function (): void {
        connectTelegramBot(['work_chat_error' => 'Chat not found', 'startapp_verified_at' => now()->toIso8601String()]);

        Livewire::test(ManageTelegramSettings::class)
            ->assertSee('Chat not found')
            ->assertSee(__('telegram.admin.startapp.verified'));
    });
});
