<?php

declare(strict_types=1);

use App\Enums\CustomerTelegramEvent;
use App\Enums\OrderStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Events\OrderCreated;
use App\Events\OrderItemsChanged;
use App\Filament\Pages\Settings\ManageTelegramSettings;
use App\Filament\Resources\Orders\OrderResource;
use App\Jobs\SendCustomerTelegramMessage;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Export;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Page;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use App\Services\Export\CustomerExportService;
use App\Services\Orders\OrderService;
use App\Services\Telegram\CustomerTelegramNotifier;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use App\Support\Money;
use App\Support\TelegramMode;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * TN-01…TN-07, NF-62…NF-64: customer messages in Telegram. Only `Http::fake()`; the queue is `sync`, so a job runs at once.
 */
beforeEach(function (): void {
    $this->withoutVite();
    config(['app.url' => 'https://shop.example.com']);
    connectTelegramBot();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
});

/** A customer with a Telegram that may be written to and an order of that customer. */
function tnRecipient(array $order = [], array $account = []): array
{
    $customer = Customer::factory()->create();
    $telegram = TelegramAccount::factory()->chatStarted()->create($account);
    $telegram->forceFill(['customer_id' => $customer->id])->save();

    static $sequence = 0;
    $number = $order['number'] ?? ($sequence++ === 0 ? 'Z-00042' : 'Z-'.str_pad((string) (100 + $sequence), 5, '0', STR_PAD_LEFT));

    return [Order::factory()->order()->create(['number' => $number] + $order + ['customer_id' => $customer->id]), $telegram, $customer];
}

function tnSent(): array
{
    return Http::recorded(fn ($request): bool => str_contains((string) $request->url(), '/sendMessage'))->map(fn (array $pair): array => $pair[0]->data())->all();
}

/** Replaces the default answer of the Bot API (the first registered fake would win otherwise). */
function tnFakeApi(mixed $response): void
{
    app()->forgetInstance(Factory::class);
    Http::clearResolvedInstances();
    Http::fake(['api.telegram.org/*' => $response]);
}

function tnSettings(array $values): void
{
    $settings = app(TelegramSettings::class);

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();
}

describe('the message of a created request (TN-02, TN-03)', function (): void {
    it('sends one message with the number, items, total, delivery and the buttons, and logs it (TN-07)', function (): void {
        [$order, $account] = tnRecipient(['delivery_method' => 'Курьер', 'delivery_address' => 'ул. Тестовая, 1', 'total' => '300.00', 'items_total' => '300.00']);
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Чайник', 'qty' => 3, 'price' => '100.00', 'total' => '300.00']);

        OrderCreated::dispatch($order);

        $sent = tnSent();
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['chat_id'])->toBe((string) $account->telegram_user_id)
            ->and($sent[0]['parse_mode'])->toBe('HTML')
            ->and($sent[0]['text'])->toContain('Z-00042')->toContain('Чайник')->toContain('× 3')->toContain('Курьер')->toContain('ул. Тестовая, 1')->toContain('300')
            ->and($sent[0]['reply_markup']['inline_keyboard'][0][0]['text'])->toBe(__('telegram_notify.button.open_order'))
            ->and($sent[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe('https://shop.example.com/tg?to=o_'.$order->uuid)
            ->and($sent[0]['reply_markup']['inline_keyboard'][1][0])->toBe(['text' => __('telegram_notify.button.stop'), 'callback_data' => 'tn:stop']);

        $row = TelegramMessage::query()->sole();
        expect($row->kind)->toBe(TelegramMessageKind::OrderCreated)
            ->and($row->status)->toBe(TelegramMessageStatus::Sent)
            ->and($row->message_id)->toBe(77)
            ->and($row->sent_at)->not->toBeNull()
            ->and($row->order_id)->toBe($order->id)
            ->and($row->telegram_account_id)->toBe($account->id);
    });

    it('shows the reservation term and falls back to a website button while the Mini App is off', function (): void {
        tnSettings(['miniapp_enabled' => false]);
        [$order] = tnRecipient();
        $order->forceFill(['type' => 'reservation', 'reserved_until' => now()->addDays(2)])->save();

        OrderCreated::dispatch($order->refresh());

        $sent = tnSent();
        expect($sent[0]['text'])->toContain(__('telegram_notify.reserved_until'))->toContain($order->reserved_until->format('d.m.Y H:i'))
            ->and($sent[0]['reply_markup']['inline_keyboard'][0][0])->not->toHaveKey('web_app')
            ->and($sent[0]['reply_markup']['inline_keyboard'][0][0]['url'])->toBe('https://shop.example.com/order/'.$order->uuid);
    });

    it('escapes everything it interpolates (TN-03)', function (): void {
        [$order] = tnRecipient(['delivery_address' => '<script>alert(1)</script> & "x"']);
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => '<b>Чайник</b> & Ко', 'qty' => 1, 'price' => '10.00', 'total' => '10.00']);

        OrderCreated::dispatch($order);

        $text = tnSent()[0]['text'];
        expect($text)->toContain('&lt;b&gt;Чайник&lt;/b&gt; &amp; Ко')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;')
            ->not->toContain('<script>')
            ->not->toContain('<b>Чайник</b>');
    });

    it('sends one message per request of a mixed cart (an order and a reservation)', function (): void {
        [$order, , $customer] = tnRecipient();
        $reservation = Order::factory()->reservation()->create(['customer_id' => $customer->id, 'telegram_account_id' => null]);

        OrderCreated::dispatch($order);
        OrderCreated::dispatch($reservation);

        expect(tnSent())->toHaveCount(2)->and(TelegramMessage::query()->count())->toBe(2);
    });
});

describe('every TN-02 event gives exactly one message', function (): void {
    it('status change: all statuses but «new», through OrderService', function (): void {
        [$order] = tnRecipient();
        $service = app(OrderService::class);

        $service->changeStatus($order, OrderStatus::Confirmed);
        $service->changeStatus($order, OrderStatus::Ready);

        $sent = tnSent();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['text'])->toContain(OrderStatus::Confirmed->label($order->type))
            ->and($sent[1]['text'])->toContain(OrderStatus::Ready->label($order->type))
            ->and(TelegramMessage::query()->where('kind', TelegramMessageKind::StatusChanged->value)->count())->toBe(2);
    });

    it('reservation expired by the system and reservation cancelled use the «истёк или снят» event', function (): void {
        [, , $customer] = tnRecipient();
        $account = TelegramAccount::query()->firstOrFail();
        $expired = Order::factory()->reservation()->create(['customer_id' => $customer->id, 'reserved_until' => now()->subHour(), 'number' => 'R-1']);
        $cancelled = Order::factory()->reservation()->create(['customer_id' => $customer->id, 'number' => 'R-2']);

        app(OrderService::class)->expireReservations();
        app(OrderService::class)->changeStatus($cancelled, OrderStatus::Cancelled);

        $kinds = TelegramMessage::query()->orderBy('id')->pluck('kind')->all();
        expect($kinds)->toBe([TelegramMessageKind::ReservationExpired, TelegramMessageKind::ReservationExpired])
            ->and(tnSent()[0]['text'])->toContain('R-1')->toContain(__('telegram_notify.expired.text'))
            ->and(tnSent()[1]['text'])->toContain('R-2')->toContain(__('telegram_notify.released.text'))
            ->and($account->id)->toBe(TelegramMessage::query()->first()->telegram_account_id);
    });

    it('reservation expiring in 6 hours: from the scheduled task, once, with the call button to the contacts page', function (): void {
        Page::query()->where('slug', 'kontakty')->firstOrFail()->forceFill(['is_active' => true])->save();
        $general = app(GeneralSettings::class);
        $general->phones = ['+7 900 111-22-33'];
        $general->save();
        [, , $customer] = tnRecipient();
        $reservation = Order::factory()->reservation()->create(['customer_id' => $customer->id, 'reserved_until' => now()->addHours(3), 'number' => 'R-7']);
        // A guest reservation bound to a Telegram without any account is warned too.
        $guestAccount = TelegramAccount::factory()->chatStarted()->create();
        Order::factory()->reservation()->create(['customer_id' => null, 'telegram_account_id' => $guestAccount->id, 'reserved_until' => now()->addHours(2)]);
        Order::factory()->reservation()->create(['customer_id' => $customer->id, 'reserved_until' => now()->addHours(30)]);

        $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();
        $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

        $sent = tnSent();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['text'])->toContain('R-7')->toContain('+7 900 111-22-33')
            ->and($sent[0]['reply_markup']['inline_keyboard'][0][1])->toBe(['text' => __('telegram_notify.button.call'), 'url' => 'https://shop.example.com/page/kontakty'])
            ->and($sent[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe('https://shop.example.com/tg?to=o_'.$reservation->uuid)
            ->and(TelegramMessage::query()->where('kind', TelegramMessageKind::ReservationExpiring->value)->count())->toBe(2);
    });

    it('items changed by staff, only with «Уведомить покупателя»', function (): void {
        [$order] = tnRecipient();
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Чайник', 'qty' => 2, 'price' => '150.00', 'total' => '300.00']);

        OrderItemsChanged::dispatch($order, 10000, 30000, false, 'batch-0');
        expect(tnSent())->toHaveCount(0);

        OrderItemsChanged::dispatch($order, 10000, 30000, true, 'batch-1');
        OrderItemsChanged::dispatch($order, 10000, 30000, true, 'batch-1');
        OrderItemsChanged::dispatch($order, 30000, 35000, true, 'batch-2');

        $sent = tnSent();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['text'])->toContain($order->number)->toContain('Чайник')
            ->and($sent[0]['text'])->toContain(__('telegram_notify.items_changed.total', ['old' => Money::format(10000), 'new' => Money::format(30000)]));
    });

    it('wholesale decision: approved and rejected', function (): void {
        Mail::fake();
        $manager = User::factory()->manager()->create();
        $opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
        [, $account, $customer] = tnRecipient();
        $approved = WholesaleApplication::factory()->forCustomer($customer)->create();

        app(WholesaleApplicationService::class)->approve($approved, $opt, $manager);

        $other = Customer::factory()->create();
        TelegramAccount::factory()->chatStarted()->create(['customer_id' => $other->id]);
        $rejected = WholesaleApplication::factory()->forCustomer($other)->create();
        app(WholesaleApplicationService::class)->reject($rejected, 'Нет ИНН <b>', $manager);

        $sent = tnSent();
        expect($sent)->toHaveCount(2)
            ->and($sent[0]['chat_id'])->toBe((string) $account->telegram_user_id)
            ->and($sent[0]['text'])->toContain(__('telegram_notify.wholesale.approved_title'))->toContain('«'.$opt->name.'»')
            ->and($sent[1]['text'])->toContain(__('telegram_notify.wholesale.rejected_title'))->toContain('Нет ИНН &lt;b&gt;')
            ->and(TelegramMessage::query()->where('kind', TelegramMessageKind::WholesaleDecision->value)->count())->toBe(2);
    });

    it('export ready: off by default, one message once the shop enables it', function (): void {
        Storage::fake('local');
        Mail::fake();
        [, , $customer] = tnRecipient();
        $export = Export::factory()->forCustomer($customer)->create();

        app(CustomerExportService::class)->generate($export);
        expect(tnSent())->toHaveCount(0);

        tnSettings(['notify_export_ready' => true]);
        $second = Export::factory()->forCustomer($customer)->create();
        app(CustomerExportService::class)->generate($second);

        $sent = tnSent();
        expect($sent)->toHaveCount(1)->and($sent[0]['text'])->toContain(__('telegram_notify.export.title'))->toContain('PDF');
    });

    it('has the TN-02 defaults: every event on except the export', function (): void {
        $settings = app(TelegramSettings::class);

        foreach (CustomerTelegramEvent::cases() as $event) {
            expect($settings->{$event->settingName()})->toBe($event !== CustomerTelegramEvent::ExportReady);
        }
    });
});

describe('deduplication (TN-04)', function (): void {
    it('never sends the same event twice and a repeated job does not resend', function (): void {
        [$order] = tnRecipient();

        OrderCreated::dispatch($order);
        OrderCreated::dispatch($order);
        $row = TelegramMessage::query()->sole();
        (new SendCustomerTelegramMessage($row->id, 'again'))->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));
        (new SendCustomerTelegramMessage($row->id, 'again'))->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));

        expect(tnSent())->toHaveCount(1)->and(TelegramMessage::query()->count())->toBe(1);
    });

    it('writes the row before the job runs and keeps it queued when the send fails and is retried', function (): void {
        tnFakeApi(Http::sequence()
            ->push(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)
            ->push(['ok' => true, 'result' => ['message_id' => 78]]));
        [, $account] = tnRecipient();
        $row = TelegramMessage::factory()->create(['telegram_account_id' => $account->id, 'kind' => TelegramMessageKind::StatusChanged, 'dedupe_key' => 'k1']);
        $job = new SendCustomerTelegramMessage($row->id, 'text');

        expect(fn () => $job->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class)))->toThrow(TelegramApiException::class);
        expect($row->refresh()->status)->toBe(TelegramMessageStatus::Queued);

        $job->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));
        $job->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));

        expect($row->refresh()->status)->toBe(TelegramMessageStatus::Sent)->and($row->message_id)->toBe(78)->and(tnSent())->toHaveCount(2);
    });

    it('marks the row failed when the last attempt fails, without a token in the error', function (): void {
        [, $account] = tnRecipient();
        $row = TelegramMessage::factory()->create(['telegram_account_id' => $account->id, 'dedupe_key' => 'k2']);

        (new SendCustomerTelegramMessage($row->id, 'text'))->failed(new RuntimeException('boom bot123456:TEST-token_abc'));

        $row->refresh();
        expect($row->status)->toBe(TelegramMessageStatus::Failed)->and($row->error)->toBe(RuntimeException::class);
    });
});

describe('conditions (TN-01, NF-63)', function (): void {
    it('writes nothing while the feature, the event or the bot is off', function (array $settings): void {
        tnSettings($settings);
        [$order] = tnRecipient();

        OrderCreated::dispatch($order);

        Http::assertNothingSent();
        expect(TelegramMessage::query()->count())->toBe(0);
    })->with([
        'feature off' => [['customer_notifications_enabled' => false]],
        'event off in the shop' => [['notify_order_created' => false]],
        'bot not connected' => [['connected_at' => null]],
    ]);

    it('records a skipped row when the recipient cannot be written to', function (array $account, string $reason): void {
        [$order] = tnRecipient(account: $account);

        OrderCreated::dispatch($order);

        Http::assertNothingSent();
        $row = TelegramMessage::query()->sole();
        expect($row->status)->toBe(TelegramMessageStatus::Skipped)->and($row->error)->toBe($reason);
    })->with([
        'chat not started' => [['chat_started_at' => null], 'chat_not_started'],
        'bot blocked' => [['bot_blocked_at' => now()], 'bot_blocked'],
        'notifications stopped' => [['notify_enabled' => false], 'notifications_stopped'],
    ]);

    it('respects the events the customer switched off in the cabinet (TN-05)', function (): void {
        [$order, , $customer] = tnRecipient();
        $customer->forceFill(['telegram_notify_events' => ['order_created']])->save();

        OrderCreated::dispatch($order);
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed);

        $rows = TelegramMessage::query()->orderBy('id')->get();
        expect($rows->pluck('status')->all())->toBe([TelegramMessageStatus::Skipped, TelegramMessageStatus::Sent])
            ->and($rows[0]->error)->toBe('disabled_by_customer')
            ->and(tnSent())->toHaveCount(1);
    });

    it('writes nothing for a request with no Telegram at all', function (): void {
        $order = Order::factory()->order()->create(['customer_id' => Customer::factory()->create()->id]);
        $guest = Order::factory()->order()->create();

        OrderCreated::dispatch($order);
        OrderCreated::dispatch($guest);

        Http::assertNothingSent();
        expect(TelegramMessage::query()->count())->toBe(0);
    });
});

describe('the recipient (TN-01, NF-62)', function (): void {
    it('prefers orders.telegram_account_id over the Telegram of the customer', function (): void {
        [$order] = tnRecipient();
        $bound = TelegramAccount::factory()->chatStarted()->create();
        $order->forceFill(['telegram_account_id' => $bound->id])->save();

        OrderCreated::dispatch($order->refresh());

        expect(tnSent()[0]['chat_id'])->toBe((string) $bound->telegram_user_id);
    });

    it('falls back to the Telegram of the order customer', function (): void {
        [$order, $account] = tnRecipient();

        expect(app(CustomerTelegramNotifier::class)->recipient($order)?->id)->toBe($account->id);
    });

    it('serves a guest request of the Mini App through its own account', function (): void {
        $account = TelegramAccount::factory()->chatStarted()->create();
        $order = Order::factory()->order()->create(['customer_id' => null, 'telegram_account_id' => $account->id]);

        OrderCreated::dispatch($order);

        expect(tnSent())->toHaveCount(1)->and(tnSent()[0]['chat_id'])->toBe((string) $account->telegram_user_id);
    });

    it('never sends the data of one customer to the Telegram of another', function (): void {
        $owner = Customer::factory()->create();
        $stranger = Customer::factory()->create();
        $account = TelegramAccount::factory()->chatStarted()->create();
        $account->forceFill(['customer_id' => $stranger->id])->save();
        $order = Order::factory()->order()->create(['customer_id' => $owner->id, 'telegram_account_id' => $account->id]);

        OrderCreated::dispatch($order);

        Http::assertNothingSent();
        expect(TelegramMessage::query()->count())->toBe(0);
    });

    it('skips an anonymized customer', function (): void {
        [$order, , $customer] = tnRecipient();
        $customer->forceFill(['anonymized_at' => now()])->save();

        OrderCreated::dispatch($order);

        Http::assertNothingSent();
    });
});

describe('a blocked bot (TG-10)', function (): void {
    it('marks the account, does not retry and does not block the status change', function (): void {
        tnFakeApi(Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403));
        [$order, $account] = tnRecipient();

        $changed = app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed);

        expect($changed->status)->toBe(OrderStatus::Confirmed)
            ->and($account->refresh()->bot_blocked_at)->not->toBeNull()
            ->and(tnSent())->toHaveCount(1);
        $row = TelegramMessage::query()->sole();
        expect($row->status)->toBe(TelegramMessageStatus::Failed)->and($row->error)->toContain('Forbidden');
    });

    it('does not let a queue failure reach the code that changes the status (NT-01)', function (): void {
        tnFakeApi(Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500));
        [$order] = tnRecipient();

        $changed = app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed);

        expect($changed->status)->toBe(OrderStatus::Confirmed);
    });
});

describe('«Не присылать уведомления» (TN-06)', function (): void {
    it('the inline button turns notifications off and confirms it; /start turns them on again', function (): void {
        $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555]);

        telegramWebhook(pressButton(1, 'tn:stop'))
            ->assertOk()
            ->assertJsonPath('method', 'answerCallbackQuery')
            ->assertJsonPath('text', __('telegram.bot.stopped'))
            ->assertJsonPath('show_alert', true);
        expect($account->refresh()->notify_enabled)->toBeFalse();

        telegramWebhook(tgMessage(2, '/start'));
        expect($account->refresh()->notify_enabled)->toBeTrue();
    });

    it('/stop does the same and later events are skipped', function (): void {
        [$order, $account] = tnRecipient(account: ['telegram_user_id' => 555]);

        telegramWebhook(tgMessage(3, '/stop'))->assertJsonPath('text', __('telegram.bot.stopped'));
        expect($account->refresh()->notify_enabled)->toBeFalse();

        OrderCreated::dispatch($order);
        Http::assertNothingSent();
        expect(TelegramMessage::query()->sole()->status)->toBe(TelegramMessageStatus::Skipped);
    });

    it('stops a message queued before the button was pressed', function (): void {
        [, $account] = tnRecipient();
        $row = TelegramMessage::factory()->create(['telegram_account_id' => $account->id, 'dedupe_key' => 'k3']);
        $account->forceFill(['notify_enabled' => false])->save();

        (new SendCustomerTelegramMessage($row->id, 'text'))->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));

        Http::assertNothingSent();
        expect($row->refresh()->status)->toBe(TelegramMessageStatus::Skipped)->and($row->error)->toBe('recipient_unavailable');
    });
});

describe('cabinet toggles (TN-05)', function (): void {
    it('shows the switches of the events the shop reports and saves the switched-off ones', function (): void {
        [, , $customer] = tnRecipient();
        signInCustomer($this, $customer);

        $html = $this->get(route('customer.notifications'))->assertOk()->assertSee('data-telegram-events', false)->getContent();
        expect($html)->toContain('value="order_created"')->toContain('value="status_changed"')->not->toContain('value="export_ready"');

        $this->post(route('customer.telegram.events'), ['events' => ['order_created', 'reservation_expiring', 'items_changed', 'wholesale_decision', 'reservation_expired']])
            ->assertRedirect(route('customer.notifications'));

        expect($customer->refresh()->telegram_notify_events)->toBe(['status_changed']);
        $this->get(route('customer.notifications'))->assertOk();
    });

    it('keeps the stored state of an event the shop does not report and clears the list when all are on', function (): void {
        [, , $customer] = tnRecipient();
        $customer->forceFill(['telegram_notify_events' => ['export_ready', 'status_changed']])->save();
        signInCustomer($this, $customer);

        $this->post(route('customer.telegram.events'), ['events' => CustomerTelegramEvent::values()])->assertRedirect();
        expect($customer->refresh()->telegram_notify_events)->toBe(['export_ready']);

        tnSettings(['notify_export_ready' => true]);
        $this->post(route('customer.telegram.events'), ['events' => CustomerTelegramEvent::values()])->assertRedirect();
        expect($customer->refresh()->telegram_notify_events)->toBeNull();
    });

    it('refuses an unknown event and a guest', function (): void {
        [, , $customer] = tnRecipient();
        signInCustomer($this, $customer);

        $this->post(route('customer.telegram.events'), ['events' => ['hack']])->assertSessionHasErrors('events.0');
        expect($customer->refresh()->telegram_notify_events)->toBeNull();

        auth('customer')->logout();
        $this->flushSession();
        $this->post(route('customer.telegram.events'), ['events' => []])->assertRedirect();
        expect($customer->refresh()->telegram_notify_events)->toBeNull();
    });

    it('changes only the signed-in customer', function (): void {
        [, , $customer] = tnRecipient();
        [, , $other] = tnRecipient();
        signInCustomer($this, $customer);

        $this->post(route('customer.telegram.events'), ['events' => []])->assertRedirect();

        expect($customer->refresh()->telegram_notify_events)->not->toBeNull()->and($other->refresh()->telegram_notify_events)->toBeNull();
    });

    it('hides the switches without a linked Telegram and shows the /stop hint', function (): void {
        $customer = Customer::factory()->create();
        signInCustomer($this, $customer);
        $this->get(route('customer.notifications'))->assertOk()->assertDontSee('data-telegram-events', false);

        [, $account, $linked] = tnRecipient(account: ['notify_enabled' => false]);
        nextRequest();
        signInCustomer($this, $linked);
        $this->get(route('customer.notifications'))->assertOk()->assertSee('data-telegram-stopped', false);
    });

    it('is the same block in the Mini App cabinet', function (): void {
        [, $account, $customer] = tnRecipient();
        signInCustomer($this, $customer);

        $this->withSession([TelegramMode::SESSION_KEY => true, TelegramMode::ACCOUNT_KEY => $account->id, TelegramMode::USER_KEY => $account->telegram_user_id])
            ->get(route('customer.notifications'))
            ->assertOk()
            ->assertSee('data-telegram-events', false)
            ->assertSee(__('telegram_notify.cabinet.events.status_changed'));

        $this->post(route('customer.telegram.events'), ['events' => []])->assertRedirect();
        expect($customer->refresh()->telegram_notify_events)->toHaveCount(6);
    });
});

describe('the shop settings (TN-02)', function (): void {
    it('saves the event switches on the «Настройки → Telegram» page', function (): void {
        actingAs(User::factory()->admin()->create());

        Livewire::test(ManageTelegramSettings::class)
            ->assertSee(__('telegram_notify.admin.section'))
            ->assertFormSet(['notify_order_created' => true, 'notify_export_ready' => false])
            ->fillForm(['notify_status_changed' => false, 'notify_export_ready' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(TelegramSettings::class);
        expect($settings->notify_status_changed)->toBeFalse()->and($settings->notify_export_ready)->toBeTrue()->and($settings->notify_order_created)->toBeTrue();
    });

    it('creates the settings with their defaults and removes them in down()', function (): void {
        $migration = require database_path('settings/2026_11_24_000510_add_telegram_customer_events.php');
        $count = fn (): int => DB::table('settings')->where('group', 'telegram')->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'notify_'))->count();

        expect($count())->toBe(7);

        $migration->down();
        expect($count())->toBe(0);

        $migration->up();
        app()->forgetInstance(TelegramSettings::class);
        expect($count())->toBe(7)
            ->and(app(TelegramSettings::class)->notify_export_ready)->toBeFalse()
            ->and(app(TelegramSettings::class)->notify_status_changed)->toBeTrue();
    });
});

describe('the order card block «Сообщения в Telegram» (TN-07)', function (): void {
    it('lists what was sent to the customer, with the reason of a skipped one, and not the work chat', function (): void {
        actingAs(User::factory()->admin()->create());
        [$order, $account] = tnRecipient();
        TelegramMessage::factory()->sent()->create(['order_id' => $order->id, 'telegram_account_id' => $account->id, 'kind' => TelegramMessageKind::StatusChanged, 'dedupe_key' => 'a']);
        TelegramMessage::factory()->create(['order_id' => $order->id, 'telegram_account_id' => $account->id, 'kind' => TelegramMessageKind::OrderCreated, 'status' => TelegramMessageStatus::Skipped, 'error' => 'bot_blocked', 'dedupe_key' => 'b']);
        TelegramMessage::factory()->create(['order_id' => $order->id, 'telegram_account_id' => null, 'chat_id' => -100500, 'kind' => TelegramMessageKind::StaffNewOrder, 'dedupe_key' => 'c']);

        $this->get(OrderResource::getUrl('view', ['record' => $order]))
            ->assertOk()
            ->assertSee(__('telegram_notify.order.section'))
            ->assertSee(TelegramMessageKind::StatusChanged->label())
            ->assertSee(__('telegram_notify.order.reasons.bot_blocked'))
            ->assertDontSee(TelegramMessageKind::StaffNewOrder->label());
    });

    it('says so when nothing was sent', function (): void {
        actingAs(User::factory()->admin()->create());
        $order = Order::factory()->order()->create();

        $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertSee(__('telegram_notify.order.empty'));
    });
});

describe('send-time re-check of the recipient (TN-01, TN-05, NF-63)', function (): void {
    it('skips a queued message when the customer unlinked Telegram during the queue delay', function (): void {
        Queue::fake();
        [$order, $telegram] = tnRecipient();
        OrderCreated::dispatch($order);
        $row = TelegramMessage::query()->sole();
        expect($row->status)->toBe(TelegramMessageStatus::Queued);

        $telegram->forceFill(['customer_id' => null])->save();
        Queue::assertPushed(SendCustomerTelegramMessage::class);
        (new SendCustomerTelegramMessage($row->id, 'text'))->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));

        Http::assertNothingSent();
        expect($row->refresh()->status)->toBe(TelegramMessageStatus::Skipped)->and($row->error)->toBe('recipient_changed');
    });

    it('skips when the account moved to another customer, the cabinet switch or the shop switch went off', function (string $change): void {
        Queue::fake();
        [$order, $telegram, $customer] = tnRecipient();
        OrderCreated::dispatch($order);
        $row = TelegramMessage::query()->sole();

        match ($change) {
            'other customer' => $telegram->forceFill(['customer_id' => Customer::factory()->create()->id])->save(),
            'cabinet' => $customer->forceFill(['telegram_notify_events' => ['order_created']])->save(),
            'shop' => connectTelegramBot(['notify_order_created' => false]),
        };
        (new SendCustomerTelegramMessage($row->id, 'text'))->handle(app(TelegramClient::class), app(CustomerTelegramNotifier::class));

        Http::assertNothingSent();
        expect($row->refresh()->status)->toBe(TelegramMessageStatus::Skipped);
    })->with(['other customer', 'cabinet', 'shop']);
});
