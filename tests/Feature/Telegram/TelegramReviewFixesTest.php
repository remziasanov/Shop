<?php

declare(strict_types=1);

use App\Enums\CustomerNotificationType;
use App\Jobs\EditTelegramMessage;
use App\Mail\TelegramLinkedMail;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramBotSetup;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use App\Settings\TelegramSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * Review fixes of stage 17: one link method for the bot and the Mini App (TG-07, TM-04), a new bot resets the startapp
 * mark (TG-11), settings writes touch only their own fields, link columns are not mass-assignable (NF-11), the
 * synchronous redraw is short and falls back to the queue (TG-05).
 */
beforeEach(function (): void {
    connectTelegramBot();
    Mail::fake();
});

describe('one linking method (TG-07, TM-04)', function (): void {
    it('moves the link of another customer for the Mini App flow: notices the old owner, notes the new one, sends no e-mail', function (): void {
        $old = Customer::factory()->create();
        $new = Customer::factory()->create(['email' => 'new.owner@example.com']);
        $account = TelegramAccount::factory()->create(['customer_id' => $old->id, 'customer_auth_version' => $old->auth_version, 'notify_enabled' => false, 'username' => 'ivan_tg']);

        $linked = app(TelegramLinkService::class)->bindCustomer($account, $new, null, replaceExisting: true, notifyByEmail: false);

        expect($linked)->toBeTrue()
            ->and($account->fresh()->customer_id)->toBe($new->id)
            ->and($account->fresh()->customer_auth_version)->toBe($new->auth_version)
            ->and($account->fresh()->notify_enabled)->toBeTrue()
            ->and(CustomerNotification::query()->where('customer_id', $old->id)->where('type', CustomerNotificationType::TelegramUnlinked->value)->count())->toBe(1)
            ->and(CustomerNotification::query()->where('customer_id', $new->id)->where('type', CustomerNotificationType::TelegramLinked->value)->count())->toBe(1);
        Mail::assertNotQueued(TelegramLinkedMail::class);
    });

    it('still refuses another customer\'s Telegram in the bot flow and still mails by default', function (): void {
        $owner = Customer::factory()->create();
        $taken = TelegramAccount::factory()->create(['customer_id' => $owner->id, 'customer_auth_version' => $owner->auth_version]);
        $customer = Customer::factory()->create(['email' => 'c@example.com']);

        expect(app(TelegramLinkService::class)->bindCustomer($taken, $customer))->toBeFalse()
            ->and($taken->fresh()->customer_id)->toBe($owner->id);

        $free = TelegramAccount::factory()->create();
        expect(app(TelegramLinkService::class)->bindCustomer($free, $customer))->toBeTrue();
        Mail::assertQueued(TelegramLinkedMail::class);
    });

    it('releases the earlier Telegram of the customer (unique customer_id)', function (): void {
        $customer = Customer::factory()->create();
        $earlier = TelegramAccount::factory()->create(['customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);
        $account = TelegramAccount::factory()->create();

        expect(app(TelegramLinkService::class)->bindCustomer($account, $customer, null, true, false))->toBeTrue()
            ->and($earlier->fresh()->customer_id)->toBeNull()
            ->and($earlier->fresh()->customer_auth_version)->toBeNull()
            ->and($account->fresh()->customer_id)->toBe($customer->id);
    });

    it('decides on the locked, fresh row, not on the stale model of the caller (concurrent link)', function (): void {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $stale = TelegramAccount::factory()->create();
        $stale->refresh();

        // Another request links the same Telegram to customer A after the caller loaded its model.
        TelegramAccount::query()->whereKey($stale->id)->update(['customer_id' => $customerA->id, 'customer_auth_version' => $customerA->auth_version]);

        $links = app(TelegramLinkService::class);
        expect($links->bindCustomer($stale, $customerB))->toBeFalse()
            ->and(TelegramAccount::query()->find($stale->id)->customer_id)->toBe($customerA->id);

        // The Mini App flow (the code of B was just entered) replaces it and tells A.
        expect($links->bindCustomer($stale, $customerB, null, true, false))->toBeTrue()
            ->and(TelegramAccount::query()->find($stale->id)->customer_id)->toBe($customerB->id)
            ->and(CustomerNotification::query()->where('customer_id', $customerA->id)->where('type', CustomerNotificationType::TelegramUnlinked->value)->count())->toBe(1);
    });

    it('answers false instead of throwing when a concurrent link wins the unique customer_id', function (): void {
        $customer = Customer::factory()->create();
        $rival = TelegramAccount::factory()->create();
        $account = TelegramAccount::factory()->create();
        $done = false;

        // Between the release of the customer's other Telegram and our write, a parallel request links the rival.
        TelegramAccount::saving(function (TelegramAccount $saving) use (&$done, $account, $rival, $customer): void {
            if ($done || $saving->id !== $account->id) {
                return;
            }

            $done = true;
            DB::table('telegram_accounts')->where('id', $rival->id)->update(['customer_id' => $customer->id]);
        });

        expect(app(TelegramLinkService::class)->bindCustomer($account, $customer, null, true, false))->toBeFalse()
            ->and($account->fresh()->customer_id)->toBeNull();
    });

    it('keeps the link columns out of mass assignment (NF-11)', function (): void {
        $account = (new TelegramAccount)->fill(['telegram_user_id' => 5, 'customer_id' => 9, 'customer_auth_version' => 3, 'user_id' => 4, 'first_name' => 'Ivan']);

        expect($account->customer_id)->toBeNull()
            ->and($account->customer_auth_version)->toBeNull()
            ->and($account->user_id)->toBeNull()
            ->and($account->first_name)->toBe('Ivan');
    });
});

describe('bot change (TG-11)', function (): void {
    it('resets the startapp mark and the chat flags when another bot is connected', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot(['startapp_verified_at' => now()->toIso8601String()]);
        TelegramAccount::factory()->chatStarted()->create();
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 999, 'is_bot' => true, 'username' => 'other_bot']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(TelegramBotSetup::class)->connect('999:NEW-token');
        app()->forgetInstance(TelegramSettings::class);

        expect(app(TelegramSettings::class)->bot_id)->toBe(999)
            ->and(app(TelegramSettings::class)->startapp_verified_at)->toBeNull()
            ->and(TelegramAccount::query()->whereNotNull('chat_started_at')->count())->toBe(0);
    });

    it('resets the startapp mark when the bot keeps its id but changes its username', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot(['startapp_verified_at' => now()->toIso8601String()]);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'renamed_bot']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(TelegramBotSetup::class)->connect();
        app()->forgetInstance(TelegramSettings::class);

        expect(app(TelegramSettings::class)->bot_username)->toBe('renamed_bot')
            ->and(app(TelegramSettings::class)->startapp_verified_at)->toBeNull();
    });

    it('keeps the mark when the same bot reconnects', function (): void {
        config(['app.url' => 'https://shop.example.com']);
        connectTelegramBot(['startapp_verified_at' => now()->toIso8601String()]);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'test_shop_bot']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(TelegramBotSetup::class)->connect();
        app()->forgetInstance(TelegramSettings::class);

        expect(app(TelegramSettings::class)->startapp_verified_at)->not->toBeNull();
    });
});

describe('settings writes (NF-61)', function (): void {
    it('does not overwrite a webhook secret changed meanwhile when the work chat error is saved', function (): void {
        $client = app(TelegramClient::class);
        expect($client->hasToken())->toBeTrue(); // loads the settings of this long-lived instance

        // «Подключить бота» in another request rotates the secret.
        app()->forgetInstance(TelegramSettings::class);
        $other = app(TelegramSettings::class);
        $other->webhook_secret = 'rotated-secret';
        $other->connected_at = '2030-01-01T00:00:00+00:00';
        $other->save();

        $client->recordWorkChatError('chat not found');

        app()->forgetInstance(TelegramSettings::class);
        $fresh = app(TelegramSettings::class);

        expect($fresh->webhook_secret)->toBe('rotated-secret')
            ->and($fresh->connected_at)->toBe('2030-01-01T00:00:00+00:00')
            ->and($fresh->work_chat_error)->toBe('chat not found');
    });

    it('does not overwrite the bot identity when the startapp mark is saved', function (): void {
        $setup = app(TelegramBotSetup::class);
        app(TelegramSettings::class)->bot_username; // load

        app()->forgetInstance(TelegramSettings::class);
        $other = app(TelegramSettings::class);
        $other->bot_id = 4242;
        $other->bot_username = 'newer_bot';
        $other->save();

        $setup->markStartappVerified();

        app()->forgetInstance(TelegramSettings::class);
        expect(app(TelegramSettings::class)->bot_username)->toBe('newer_bot')
            ->and(app(TelegramSettings::class)->bot_id)->toBe(4242)
            ->and(app(TelegramSettings::class)->startapp_verified_at)->not->toBeNull();
    });
});

describe('synchronous redraw in the webhook (TG-05)', function (): void {
    function confirmCustomerPress(): array
    {
        $customer = Customer::factory()->create(['email' => 'ivan@mail.ru']);
        $link = app(TelegramLinkService::class)->customerLink($customer);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $question = telegramWebhook(tgMessage(1, '/start '.$query['start']));

        return pressButton(2, $question->json('reply_markup.inline_keyboard.0.0.callback_data'));
    }

    it('asks editMessageText with a 2 s timeout', function (): void {
        $press = confirmCustomerPress();
        $timeouts = [];
        Http::fake([
            'api.telegram.org/*' => function ($request, array $options) use (&$timeouts) {
                $timeouts[basename($request->url())] = $options['timeout'] ?? null;

                return Http::response(['ok' => true, 'result' => true]);
            },
        ]);

        telegramWebhook($press)->assertJsonPath('method', 'answerCallbackQuery');

        expect($timeouts)->toHaveKey('editMessageText')->and($timeouts['editMessageText'])->toBe(2);
    });

    it('queues the redraw when the call times out, and still answers the callback', function (): void {
        Bus::fake([EditTelegramMessage::class]);
        $press = confirmCustomerPress();
        Http::fake([
            'api.telegram.org/*/editMessageText' => fn () => throw new ConnectionException('cURL error 28: timeout'),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        telegramWebhook($press)->assertOk()->assertJsonPath('method', 'answerCallbackQuery');

        Bus::assertDispatched(EditTelegramMessage::class, fn (EditTelegramMessage $job): bool => $job->chatId === '555' && $job->messageId === 9 && $job->text === __('telegram.link.done_customer'));
    });

    it('does not queue the redraw for a final answer such as «message is not modified»', function (): void {
        Bus::fake([EditTelegramMessage::class]);
        $press = confirmCustomerPress();
        Http::fake([
            'api.telegram.org/*/editMessageText' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'], 400),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        telegramWebhook($press)->assertOk();

        Bus::assertNotDispatched(EditTelegramMessage::class);
    });

    it('delivers the queued redraw with the normal client', function (): void {
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        (new EditTelegramMessage('555', 9, 'Готово', ['inline_keyboard' => []]))->handle(app(TelegramClient::class));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/editMessageText') && $request['message_id'] === 9 && $request['text'] === 'Готово');
    });
});
