<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * TG-05, TG-09: the bot commands, contacts and chat member updates; replies come back in the webhook body.
 */
beforeEach(function (): void {
    connectTelegramBot();
    Http::fake();
});

it('/start greets with the open-shop button in the response body, creates the account and starts the chat (TG-05, TG-06)', function (): void {
    telegramWebhook(tgMessage(1, '/start'))
        ->assertOk()
        ->assertJsonPath('method', 'sendMessage')
        ->assertJsonPath('chat_id', '555')
        ->assertJsonPath('reply_markup.inline_keyboard.0.0.text', __('telegram.bot.open_shop'))
        ->assertJsonPath('reply_markup.inline_keyboard.0.0.web_app.url', rtrim((string) config('app.url'), '/').'/tg');

    $account = TelegramAccount::query()->firstOrFail();
    expect($account->telegram_user_id)->toBe(555)
        ->and($account->username)->toBe('ivan_tg')
        ->and($account->chat_started_at)->not->toBeNull()
        ->and($account->notify_enabled)->toBeTrue();
    Http::assertNothingSent();
});

it('/start uses the custom welcome text and falls back to the website button without the Mini App', function (): void {
    $settings = app(TelegramSettings::class);
    $settings->welcome_text = 'Привет из магазина';
    $settings->miniapp_enabled = false;
    $settings->save();

    telegramWebhook(tgMessage(2, '/start'))
        ->assertJsonPath('text', 'Привет из магазина')
        ->assertJsonPath('reply_markup.inline_keyboard.0.0.url', rtrim((string) config('app.url'), '/').'/');
});

it('/help answers with the help text, custom or default', function (): void {
    telegramWebhook(tgMessage(3, '/help'))->assertJsonPath('text', __('telegram.bot.help'));

    $settings = app(TelegramSettings::class);
    $settings->help_text = 'Свой текст помощи';
    $settings->save();

    telegramWebhook(tgMessage(4, '/help@test_shop_bot'))->assertJsonPath('text', 'Свой текст помощи');
});

it('/stop turns notifications off and /start turns them on again (TG-05, TN-06)', function (): void {
    telegramWebhook(tgMessage(5, '/start'));
    telegramWebhook(tgMessage(6, '/stop'))->assertJsonPath('text', 'Уведомления отключены. Включить — /start');

    expect(TelegramAccount::query()->firstOrFail()->notify_enabled)->toBeFalse();

    telegramWebhook(tgMessage(7, '/start'));

    expect(TelegramAccount::query()->firstOrFail()->notify_enabled)->toBeTrue();
});

it('/orders shows the Мои заказы button and the latest requests of this Telegram', function (): void {
    $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555]);
    $order = Order::factory()->create(['telegram_account_id' => $account->id]);
    Order::factory()->create(); // somebody else's

    $response = telegramWebhook(tgMessage(8, '/orders'))->assertOk();

    $response->assertJsonPath('reply_markup.inline_keyboard.0.0.text', __('telegram.bot.my_orders'));
    expect($response->json('text'))->toContain($order->number)->and($response->json('reply_markup.inline_keyboard'))->toHaveCount(1);
});

it('/orders adds the Рабочее место button for staff when the workplace is on', function (): void {
    $staff = User::factory()->manager()->create();
    TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555, 'user_id' => $staff->id]);

    telegramWebhook(tgMessage(9, '/orders'))->assertJsonCount(1, 'reply_markup.inline_keyboard');

    $settings = app(TelegramSettings::class);
    $settings->staff_workplace_enabled = true;
    $settings->save();

    telegramWebhook(tgMessage(10, '/orders'))
        ->assertJsonCount(2, 'reply_markup.inline_keyboard')
        ->assertJsonPath('reply_markup.inline_keyboard.1.0.text', __('telegram.bot.workplace'));
});

it('/orders lists the requests of the linked customer', function (): void {
    $customer = Customer::factory()->create();
    TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555, 'customer_id' => $customer->id]);
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    expect(telegramWebhook(tgMessage(11, '/orders'))->json('text'))->toContain($order->number);
});

describe('contact (TG-09)', function (): void {
    it('saves the own number normalized to +7XXXXXXXXXX with phone_verified_at', function (): void {
        $update = tgMessage(20, '', 555, ['contact' => ['phone_number' => '79991234567', 'first_name' => 'Ivan', 'user_id' => 555]]);
        unset($update['message']['text']);

        telegramWebhook($update)->assertJsonPath('text', __('telegram.bot.contact_saved'));

        $account = TelegramAccount::query()->firstOrFail();
        expect($account->phone)->toBe('+79991234567')->and($account->phone_verified_at)->not->toBeNull();
    });

    it('keeps a foreign number as is with a plus', function (): void {
        $update = tgMessage(21, '', 555, ['contact' => ['phone_number' => '380501234567', 'first_name' => 'Ivan', 'user_id' => 555]]);
        unset($update['message']['text']);

        telegramWebhook($update);

        expect(TelegramAccount::query()->firstOrFail()->phone)->toBe('+380501234567');
    });

    it('refuses a forwarded contact of another user', function (): void {
        $update = tgMessage(22, '', 555, ['contact' => ['phone_number' => '79990001122', 'first_name' => 'Petr', 'user_id' => 777]]);
        unset($update['message']['text']);

        telegramWebhook($update)->assertJsonPath('text', __('telegram.bot.contact_not_own'));

        $update = tgMessage(23, '', 555, ['contact' => ['phone_number' => '79990001122', 'first_name' => 'Petr']]);
        unset($update['message']['text']);
        telegramWebhook($update);

        expect(TelegramAccount::query()->firstOrFail()->phone)->toBeNull();
    });
});

describe('other messages (TG-05)', function (): void {
    it('marks the chat as started for write_access_allowed without replying', function (): void {
        $update = tgMessage(30, '', 555, ['write_access_allowed' => ['web_app_name' => 'shop']]);
        unset($update['message']['text']);

        telegramWebhook($update)->assertOk()->assertContent('');

        expect(TelegramAccount::query()->firstOrFail()->chat_started_at)->not->toBeNull();
    });

    it('auto-replies with the shop button and the phone at most once per 10 minutes per chat', function (): void {
        Cache::flush();
        $general = app(GeneralSettings::class);
        $general->phones = ['+7 495 000-00-00'];
        $general->save();

        $first = telegramWebhook(tgMessage(31, 'Здравствуйте, есть в наличии?'))->assertJsonPath('method', 'sendMessage');
        expect($first->json('text'))->toBe(__('telegram.bot.autoreply', ['phone' => '+7 495 000-00-00']))
            ->and($first->json('reply_markup.inline_keyboard.0.0.text'))->toBe(__('telegram.bot.open_shop'));

        telegramWebhook(tgMessage(32, 'Алло?'))->assertContent('');
        telegramWebhook(tgMessage(33, 'Unknown', 888))->assertJsonPath('chat_id', '888');
    });

    it('ignores group messages and bots, including the work chat', function (): void {
        $group = tgMessage(40, 'hello');
        $group['message']['chat'] = ['id' => -1001, 'type' => 'supergroup'];
        $bot = tgMessage(41, '/start');
        $bot['message']['from']['is_bot'] = true;

        telegramWebhook($group)->assertOk()->assertContent('');
        telegramWebhook($bot)->assertOk()->assertContent('');

        expect(TelegramAccount::query()->count())->toBe(0);
    });

    it('answers a callback of another stage with a polite «Скоро»', function (): void {
        telegramWebhook([
            'update_id' => 50,
            'callback_query' => ['id' => 'cb1', 'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ivan'], 'data' => 'ord:1:confirm', 'message' => ['message_id' => 5, 'chat' => ['id' => 555]]],
        ])->assertJsonPath('method', 'answerCallbackQuery')->assertJsonPath('callback_query_id', 'cb1')->assertJsonPath('text', __('telegram.callback.soon'));
    });
});

describe('my_chat_member (TG-05)', function (): void {
    function chatMemberUpdate(int $id, array $chat, string $status): array
    {
        return ['update_id' => $id, 'my_chat_member' => ['chat' => $chat, 'from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Ivan'], 'date' => time(), 'new_chat_member' => ['status' => $status, 'user' => ['id' => 123456, 'is_bot' => true]]]];
    }

    it('marks the account blocked when the user kicks the bot and resets it on member', function (): void {
        $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555]);

        telegramWebhook(chatMemberUpdate(60, ['id' => 555, 'type' => 'private'], 'kicked'))->assertOk();
        expect($account->refresh()->bot_blocked_at)->not->toBeNull();

        telegramWebhook(chatMemberUpdate(61, ['id' => 555, 'type' => 'private'], 'member'))->assertOk();
        expect($account->refresh()->bot_blocked_at)->toBeNull();
    });

    it('records a work chat error when the bot is removed from the work chat and leaves accounts alone', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->work_chat_id = '-1001';
        $settings->save();
        $account = TelegramAccount::factory()->chatStarted()->create();

        telegramWebhook(chatMemberUpdate(62, ['id' => -1001, 'type' => 'supergroup'], 'kicked'))->assertOk();

        expect(app(TelegramSettings::class)->work_chat_error)->toBe(__('telegram.admin.work_chat_removed'))
            ->and($account->refresh()->bot_blocked_at)->toBeNull();

        telegramWebhook(chatMemberUpdate(63, ['id' => -1001, 'type' => 'supergroup'], 'administrator'));
        expect(app(TelegramSettings::class)->work_chat_error)->toBeNull();
    });

    it('ignores other groups', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->work_chat_id = '-1001';
        $settings->save();

        telegramWebhook(chatMemberUpdate(64, ['id' => -2002, 'type' => 'group'], 'left'))->assertOk();

        expect(app(TelegramSettings::class)->work_chat_error)->toBeNull();
    });
});
