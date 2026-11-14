<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\TelegramLinkPurpose;
use App\Mail\TelegramLinkedMail;
use App\Models\AdminAuditEntry;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Services\Telegram\TelegramLinkService;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * TG-07, TG-08: one-time links, the confirmation question, anti-hijack mail, expiry, reuse and following requests.
 */
beforeEach(function (): void {
    connectTelegramBot();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
});

/** The raw token of an issued deep link `https://t.me/test_shop_bot?start=c_xxx`. */
function startParam(string $link): string
{
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return $query['start'];
}

/**
 * @return array<string, mixed>
 */
describe('issuing', function (): void {
    it('creates a 24-byte base64url token and stores only its SHA-256 (TG-07)', function (): void {
        $customer = Customer::factory()->create();

        $link = app(TelegramLinkService::class)->customerLink($customer);
        $param = startParam($link);

        expect($link)->toStartWith('https://t.me/test_shop_bot?start=c_')
            ->and($param)->toMatch('/^c_[A-Za-z0-9_-]{32}$/')
            ->and(strlen($param))->toBeLessThanOrEqual(64);

        $row = TelegramLinkToken::query()->firstOrFail();
        expect($row->token_hash)->toBe(hash('sha256', substr($param, 2)))
            ->and($row->purpose)->toBe(TelegramLinkPurpose::Customer)
            ->and($row->customer_id)->toBe($customer->id)
            ->and($row->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();
        expect(json_encode(TelegramLinkToken::query()->get()->toArray()))->not->toContain(substr($param, 2));
    });

    it('uses 15 minutes for staff and 7 days for requests, and lists every order of the checkout', function (): void {
        $service = app(TelegramLinkService::class);
        $orders = Order::factory()->count(2)->create();

        $service->staffLink(User::factory()->manager()->create());
        $service->orderLink($orders);

        $staff = TelegramLinkToken::query()->where('purpose', 'staff')->firstOrFail();
        $order = TelegramLinkToken::query()->where('purpose', 'order')->firstOrFail();

        expect($staff->expires_at->lessThanOrEqualTo(now()->addMinutes(16)))->toBeTrue()
            ->and($order->expires_at->between(now()->addDays(6)->addHours(23), now()->addDays(7)->addMinute()))->toBeTrue()
            ->and($order->order_ids)->toBe($orders->pluck('id')->all());
    });

    it('issues nothing while the bot is not connected, and no order link while customer notifications are off', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->customer_notifications_enabled = false;
        $settings->save();

        expect(app(TelegramLinkService::class)->orderLink(Order::factory()->count(1)->create()))->toBeNull();

        $settings->connected_at = null;
        $settings->save();

        expect(app(TelegramLinkService::class)->customerLink(Customer::factory()->create()))->toBeNull()
            ->and(TelegramLinkToken::query()->count())->toBe(0);
    });
});

describe('customer link (c_)', function (): void {
    it('asks with the masked e-mail and links only after «Подключить»; then mails the owner and writes a cabinet note (TG-07)', function (): void {
        $customer = Customer::factory()->create(['email' => 'ivan@mail.ru']);
        $param = startParam(app(TelegramLinkService::class)->customerLink($customer));

        $question = telegramWebhook(tgMessage(1, '/start '.$param));
        $question->assertJsonPath('text', __('telegram.link.confirm_customer', ['email' => 'i***@mail.ru']))
            ->assertJsonPath('reply_markup.inline_keyboard.0.0.text', __('telegram.link.button_connect'))
            ->assertJsonPath('reply_markup.inline_keyboard.0.1.text', __('telegram.link.button_decline'));

        // The link alone changes nothing.
        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull()
            ->and(TelegramLinkToken::query()->firstOrFail()->used_at)->toBeNull();
        Mail::assertNothingQueued();

        $connect = $question->json('reply_markup.inline_keyboard.0.0.callback_data');
        expect(strlen($connect))->toBeLessThanOrEqual(64);

        telegramWebhook(pressButton(2, $connect))
            ->assertJsonPath('method', 'answerCallbackQuery')
            ->assertJsonPath('text', __('telegram.link.done_toast'));

        $account = TelegramAccount::query()->firstOrFail();
        expect($account->customer_id)->toBe($customer->id)
            ->and($account->customer_auth_version)->toBe($customer->auth_version)
            ->and(TelegramLinkToken::query()->firstOrFail()->used_at)->not->toBeNull();

        Mail::assertQueued(TelegramLinkedMail::class, fn (TelegramLinkedMail $mail): bool => $mail->hasTo('ivan@mail.ru') && $mail->telegram === '@ivan_tg' && ! $mail->staff);
        expect(CustomerNotification::query()->where('customer_id', $customer->id)->where('type', 'telegram_linked')->count())->toBe(1);

        // The question message is redrawn with the result and the shop button (the one synchronous call).
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/editMessageText')
            && $request['text'] === __('telegram.link.done_customer')
            && $request['reply_markup']['inline_keyboard'][0][0]['text'] === __('telegram.bot.open_shop'));
    });

    it('«Это не я» links nothing, burns the token and sends no mail', function (): void {
        $customer = Customer::factory()->create();
        $param = startParam(app(TelegramLinkService::class)->customerLink($customer));
        $question = telegramWebhook(tgMessage(1, '/start '.$param));

        telegramWebhook(pressButton(2, $question->json('reply_markup.inline_keyboard.0.1.callback_data')))
            ->assertJsonPath('text', __('telegram.link.declined_toast'));

        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull();
        Mail::assertNothingQueued();

        // The burnt token cannot be confirmed any more.
        telegramWebhook(pressButton(3, $question->json('reply_markup.inline_keyboard.0.0.callback_data')))
            ->assertJsonPath('text', __('telegram.link.expired_toast'));
        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull();
    });

    it('says the link is outdated for an expired, used or unknown token (TG-07)', function (): void {
        $customer = Customer::factory()->create();
        $service = app(TelegramLinkService::class);

        $expired = startParam($service->customerLink($customer));
        TelegramLinkToken::query()->update(['expires_at' => now()->subMinute()]);
        $used = startParam($service->customerLink($customer));
        TelegramLinkToken::query()->where('expires_at', '>', now())->update(['used_at' => now()]);

        foreach ([$expired, $used, 'c_'.str_repeat('x', 32), 'c_short'] as $i => $param) {
            $response = telegramWebhook(tgMessage(10 + $i, '/start '.$param))->assertOk();

            // A malformed parameter is just a welcome; the rest are «устарела».
            expect($response->json('text'))->toBe($param === 'c_short' ? __('telegram.bot.welcome', ['shop' => app(GeneralSettings::class)->shop_name]) : __('telegram.link.expired'));
        }

        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull();
    });

    it('does not accept the token twice (one-time)', function (): void {
        $customer = Customer::factory()->create();
        $param = startParam(app(TelegramLinkService::class)->customerLink($customer));
        $connect = telegramWebhook(tgMessage(1, '/start '.$param))->json('reply_markup.inline_keyboard.0.0.callback_data');

        telegramWebhook(pressButton(2, $connect));
        telegramWebhook(pressButton(3, $connect))->assertJsonPath('text', __('telegram.link.expired_toast'));

        Mail::assertQueued(TelegramLinkedMail::class, 1);
    });

    it('keeps the link when this Telegram already belongs to another customer, with the message of the TZ (TG-07)', function (): void {
        $other = Customer::factory()->create();
        $mine = Customer::factory()->create();
        TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555, 'customer_id' => $other->id]);
        $param = startParam(app(TelegramLinkService::class)->customerLink($mine));

        telegramWebhook(tgMessage(1, '/start '.$param))->assertJsonPath('text', 'Этот Telegram уже подключён к другому аккаунту. Отключите его там или войдите через Mini App.');

        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBe($other->id);
    });

    it('moves the customer to the new Telegram: one customer, one Telegram', function (): void {
        $customer = Customer::factory()->create();
        $old = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 111, 'customer_id' => $customer->id]);
        $param = startParam(app(TelegramLinkService::class)->customerLink($customer));
        $connect = telegramWebhook(tgMessage(1, '/start '.$param, 555))->json('reply_markup.inline_keyboard.0.0.callback_data');

        telegramWebhook(pressButton(2, $connect, 555));

        expect($old->refresh()->customer_id)->toBeNull()
            ->and(TelegramAccount::query()->where('telegram_user_id', 555)->value('customer_id'))->toBe($customer->id);
    });

    it('rejects a forged confirmation for a wrong hash', function (): void {
        $customer = Customer::factory()->create();
        $param = startParam(app(TelegramLinkService::class)->customerLink($customer));
        telegramWebhook(tgMessage(1, '/start '.$param));
        $id = TelegramLinkToken::query()->firstOrFail()->id;

        telegramWebhook(pressButton(2, 'lc:'.$id.':deadbeef'))->assertJsonPath('text', __('telegram.link.expired_toast'));

        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull();
    });

    it('unlinks from the cabinet side', function (): void {
        $customer = Customer::factory()->create();
        TelegramAccount::factory()->create(['customer_id' => $customer->id, 'customer_auth_version' => 1]);

        app(TelegramLinkService::class)->unlinkCustomer($customer);

        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull();
    });
});

describe('staff link (s_)', function (): void {
    it('asks with the staff name, links on the button, mails the user and writes admin_audit_log (TG-07)', function (): void {
        $user = User::factory()->manager()->create(['name' => 'Анна Менеджер', 'email' => 'anna@example.com']);
        $param = startParam(app(TelegramLinkService::class)->staffLink($user));

        $question = telegramWebhook(tgMessage(1, '/start '.$param))
            ->assertJsonPath('text', __('telegram.link.confirm_staff', ['name' => 'Анна Менеджер']));
        expect(TelegramAccount::query()->firstOrFail()->user_id)->toBeNull();

        telegramWebhook(pressButton(2, $question->json('reply_markup.inline_keyboard.0.0.callback_data')))
            ->assertJsonPath('text', __('telegram.link.done_toast'));

        expect(TelegramAccount::query()->firstOrFail()->user_id)->toBe($user->id);
        Mail::assertQueued(TelegramLinkedMail::class, fn (TelegramLinkedMail $mail): bool => $mail->hasTo('anna@example.com') && $mail->staff);

        $entry = AdminAuditEntry::query()->where('action', AuditAction::UserTelegramLinked->value)->firstOrFail();
        expect($entry->user_id)->toBe($user->id)->and(json_encode($entry->data))->not->toContain('ivan_tg')->not->toContain('anna');
    });

    it('does not link a Telegram that already belongs to another staff user', function (): void {
        $first = User::factory()->manager()->create();
        TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555, 'user_id' => $first->id]);
        $param = startParam(app(TelegramLinkService::class)->staffLink(User::factory()->manager()->create()));

        telegramWebhook(tgMessage(1, '/start '.$param))->assertJsonPath('text', __('telegram.link.already_linked_staff'));
    });

    it('does not accept a customer token as a staff link', function (): void {
        $customer = Customer::factory()->create();
        $raw = substr(startParam(app(TelegramLinkService::class)->customerLink($customer)), 2);

        telegramWebhook(tgMessage(1, '/start s_'.$raw))->assertJsonPath('text', __('telegram.link.expired'));
    });
});

describe('request link (o_, TG-08)', function (): void {
    it('binds every request of the checkout to the Telegram and answers with numbers and statuses', function (): void {
        $orders = Order::factory()->count(2)->create();
        $param = startParam(app(TelegramLinkService::class)->orderLink($orders));

        $response = telegramWebhook(tgMessage(1, '/start '.$param))->assertOk();

        $account = TelegramAccount::query()->firstOrFail();
        expect(Order::query()->whereIn('id', $orders->pluck('id'))->pluck('telegram_account_id')->unique()->all())->toBe([$account->id])
            ->and($response->json('text'))->toContain(__('telegram.link.orders_followed'))->toContain($orders[0]->number)->toContain($orders[1]->number)
            ->and($response->json('reply_markup.inline_keyboard.0.0.text'))->toBe(__('telegram.bot.open_shop'))
            ->and(TelegramLinkToken::query()->firstOrFail()->used_at)->not->toBeNull();

        telegramWebhook(tgMessage(2, '/start '.$param))->assertJsonPath('text', __('telegram.link.expired'));
    });

    it('leaves a request that another Telegram already follows', function (): void {
        $other = TelegramAccount::factory()->create();
        $mine = Order::factory()->create();
        $taken = Order::factory()->create(['telegram_account_id' => $other->id]);
        $param = startParam(app(TelegramLinkService::class)->orderLink([$mine, $taken]));

        telegramWebhook(tgMessage(1, '/start '.$param));

        expect($taken->refresh()->telegram_account_id)->toBe($other->id)->and($mine->refresh()->telegram_account_id)->not->toBeNull();
    });
});

describe('bindCustomer (used by the Mini App sign-in link, TM-04)', function (): void {
    it('links without a token, remembers auth_version, notifies the cabinet and mails the owner', function (): void {
        $customer = Customer::factory()->create(['email' => 'ivan@mail.ru']);
        $account = TelegramAccount::factory()->chatStarted()->create(['username' => 'ivan_tg']);

        expect(app(TelegramLinkService::class)->bindCustomer($account, $customer))->toBeTrue();

        expect($account->refresh()->customer_id)->toBe($customer->id)->and($account->customer_auth_version)->toBe($customer->auth_version);
        Mail::assertQueued(TelegramLinkedMail::class, fn (TelegramLinkedMail $mail): bool => $mail->hasTo('ivan@mail.ru'));
        expect(CustomerNotification::query()->where('type', 'telegram_linked')->count())->toBe(1);
    });

    it('refuses a Telegram that belongs to another customer', function (): void {
        $account = TelegramAccount::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        expect(app(TelegramLinkService::class)->bindCustomer($account, Customer::factory()->create()))->toBeFalse();
        Mail::assertNothingQueued();
    });
});
