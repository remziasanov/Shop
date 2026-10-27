<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Mail\TestMail;
use App\Models\User;
use App\Settings\NotificationSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('sends a test email to the first shop address (NT-04)', function (): void {
    Mail::fake();
    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com'];
    $settings->save();

    Livewire::test(ManageNotificationSettings::class)
        ->callAction(TestAction::make('sendTestMail')->schemaComponent('testMailActions', schema: 'form'))
        ->assertNotified();

    Mail::assertSent(TestMail::class, fn ($m): bool => $m->hasTo('shop@example.com'));
});

it('falls back to the admin email for the test mail', function (): void {
    Mail::fake();

    Livewire::test(ManageNotificationSettings::class)
        ->callAction(TestAction::make('sendTestMail')->schemaComponent('testMailActions', schema: 'form'));

    Mail::assertSent(TestMail::class, fn ($m): bool => $m->hasTo($this->admin->email));
});

it('shows a Telegram test error without the token', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    Livewire::test(ManageNotificationSettings::class)
        ->fillForm(['telegram_bot_token' => '999:LEAKME', 'telegram_chat_id' => '5'])
        ->callAction(TestAction::make('sendTestMessage')->schemaComponent('testTelegramActions', schema: 'form'))
        ->assertNotified(Notification::make()->danger()->title(__('notifications.test.telegram_failed', ['error' => 'Telegram API error (HTTP 401): Unauthorized'])));
});

it('sends a Telegram test message', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    Livewire::test(ManageNotificationSettings::class)
        ->fillForm(['telegram_bot_token' => '999:abc', 'telegram_chat_id' => '5'])
        ->callAction(TestAction::make('sendTestMessage')->schemaComponent('testTelegramActions', schema: 'form'))
        ->assertNotified(__('notifications.test.telegram_sent'));

    Http::assertSent(fn ($r): bool => str_contains($r->url(), '/bot999:abc/sendMessage'));
});

it('keeps the token out of the browser state and keeps it on an empty save', function (): void {
    $component = Livewire::test(ManageNotificationSettings::class)
        ->fillForm(['telegram_bot_token' => '777:TOPSECRET', 'telegram_chat_id' => '5'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(NotificationSettings::class)->telegram_bot_token)->toBe('777:TOPSECRET');
    $component->assertSet('data.telegram_bot_token', null);
    expect($component->html())->not->toContain('TOPSECRET')->and($component->html())->toContain(__('shop.settings.notifications.token_is_set'));

    $fresh = Livewire::test(ManageNotificationSettings::class);
    $fresh->assertSet('data.telegram_bot_token', null);
    expect(json_encode($fresh->snapshot))->not->toContain('TOPSECRET');

    $fresh->fillForm(['telegram_chat_id' => '6'])->call('save');
    expect(app(NotificationSettings::class)->telegram_bot_token)->toBe('777:TOPSECRET');

    $fresh->fillForm(['remove_telegram_token' => true])->call('save');
    expect(app(NotificationSettings::class)->telegram_bot_token)->toBeNull();
});
