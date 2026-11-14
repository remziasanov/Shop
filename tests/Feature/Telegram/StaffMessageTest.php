<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_staff.php';

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Jobs\SendOrderNotifications;
use App\Jobs\SendStaffPersonalNotification;
use App\Jobs\SendTelegramNotification;
use App\Models\Order;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Orders\OrderService;
use App\Services\Telegram\Staff\StaffOrderMessage;
use App\Services\Telegram\Staff\StaffOrderNotifier;
use App\Services\Users\PermissionService;
use App\Settings\NotificationSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * TS-01: the work chat message with the channel, the status and the buttons by type and status; the log for the redraw;
 * TS-08: personal messages with the rights check; `/start staff`.
 */
beforeEach(function (): void {
    staffBot();
});

function buttonsOf(?array $markup): array
{
    $texts = [];

    foreach ($markup['inline_keyboard'] ?? [] as $row) {
        foreach ($row as $button) {
            $texts[] = $button['text'];
        }
    }

    return $texts;
}

it('shows the channel and the current status in the new request message (TS-01)', function (): void {
    $order = Order::factory()->order()->channel(OrderChannel::Telegram)->create();

    $text = app(StaffOrderMessage::class)->text($order);

    expect($text)->toContain('Канал: Telegram')->toContain('Статус: <b>Новый</b>');
});

it('offers the buttons of an order by status: confirm and cancel, then ready and cancel (TS-01)', function (): void {
    $message = app(StaffOrderMessage::class);
    $order = Order::factory()->order()->create();

    expect(buttonsOf($message->markup($order)))->toBe(['Подтвердить', 'Отменить', 'Открыть в админке', 'Рабочее место']);

    $order->forceFill(['status' => OrderStatus::Confirmed])->save();
    expect(buttonsOf($message->markup($order)))->toBe(['Готов к выдаче', 'Отменить', 'Открыть в админке', 'Рабочее место']);

    $order->forceFill(['status' => OrderStatus::Completed])->save();
    expect(buttonsOf($message->markup($order)))->toBe(['Открыть в админке', 'Рабочее место']);
});

it('offers the buttons of a reservation and of an inquiry (TS-01)', function (): void {
    $message = app(StaffOrderMessage::class);
    $reservation = Order::factory()->reservation()->create();
    $inquiry = Order::factory()->inquiry()->create();

    expect(buttonsOf($message->markup($reservation)))->toBe(['Подтвердить резерв', 'Продлить на 24 ч', 'Отменить', 'Открыть в админке', 'Рабочее место'])
        ->and(buttonsOf($message->markup($inquiry)))->toBe(['В работу', 'Закрыть', 'Открыть в админке', 'Рабочее место']);

    $reservation->forceFill(['status' => OrderStatus::Reserved])->save();
    $inquiry->forceFill(['status' => OrderStatus::InProgress])->save();

    expect(buttonsOf($message->markup($reservation)))->toBe(['Продлить на 24 ч', 'Отменить', 'Открыть в админке', 'Рабочее место'])
        ->and(buttonsOf($message->markup($inquiry)))->toBe(['Закрыть', 'Открыть в админке', 'Рабочее место']);
});

it('keeps callback_data inside 64 bytes in the form o:action:id:version (TS-03)', function (): void {
    $order = Order::factory()->reservation()->create();
    $order->forceFill(['version' => 987654321])->save();
    $order->id = 123456789012345;

    foreach (app(StaffOrderMessage::class)->markup($order)['inline_keyboard'][0] as $button) {
        expect(strlen($button['callback_data']))->toBeLessThanOrEqual(64)
            ->and($button['callback_data'])->toMatch('/^o:[a-z]{2}:123456789012345:987654321$/');
    }
});

it('asks «Точно отменить?» after the first tap of the cancel button (TS-04)', function (): void {
    $order = Order::factory()->order()->create();

    expect(buttonsOf(app(StaffOrderMessage::class)->markup($order, cancelArmed: true)))->toContain('Точно отменить?')->not->toContain('Отменить');
});

it('links the workplace through startapp once the links are verified, else through start=staff (TS-01)', function (): void {
    $order = Order::factory()->order()->create();
    $buttons = fn (): array => collect(app(StaffOrderMessage::class)->markup($order)['inline_keyboard'])->flatten(1)->keyBy('text')->all();

    expect($buttons()['Рабочее место']['url'])->toBe('https://t.me/test_shop_bot?start=staff')
        ->and($buttons()['Открыть в админке']['url'])->toContain('/admin/');

    staffBot(['startapp_verified_at' => now()->toIso8601String()]);

    expect($buttons()['Рабочее место']['url'])->toBe('https://t.me/test_shop_bot?startapp=staff_o_'.$order->id);
});

it('shows no buttons while they are off and no workplace link while the workplace is off (TS-01, NF-63)', function (): void {
    $order = Order::factory()->order()->create();

    staffBot(['work_chat_buttons_enabled' => false]);
    expect(app(StaffOrderMessage::class)->markup($order))->toBeNull();

    staffBot(['work_chat_buttons_enabled' => true, 'staff_workplace_enabled' => false]);
    expect(buttonsOf(app(StaffOrderMessage::class)->markup($order)))->toBe(['Подтвердить', 'Отменить', 'Открыть в админке']);
});

it('sends the work chat message with the keyboard and logs chat and message id for the redraw (TS-01)', function (): void {
    fakeTelegramSend(77, -1001);
    $order = Order::factory()->order()->channel(OrderChannel::Pwa)->create();

    (new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST))->handle(app(TelegramNotifier::class));

    $sent = telegramCalls()['sendMessage'][0];
    expect($sent['chat_id'])->toBe('-1001')
        ->and($sent['text'])->toContain('Канал: Приложение')
        ->and($sent['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('o:cf:'.$order->id.':'.$order->refresh()->version);

    $log = TelegramMessage::query()->firstOrFail();
    expect($log->kind)->toBe(TelegramMessageKind::StaffNewOrder)
        ->and($log->order_id)->toBe($order->id)
        ->and($log->chat_id)->toBe(-1001)
        ->and($log->message_id)->toBe(77)
        ->and($log->status)->toBe(TelegramMessageStatus::Sent);
});

it('does not send the same work chat message twice (TS-01)', function (): void {
    fakeTelegramSend();
    $order = Order::factory()->order()->create();
    $job = new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST);

    $job->handle(app(TelegramNotifier::class));
    $job->handle(app(TelegramNotifier::class));

    expect(telegramCalls()['sendMessage'])->toHaveCount(1)->and(TelegramMessage::query()->count())->toBe(1);
});

it('sends the plain v2.0 message without a keyboard while the buttons are off (NF-63)', function (): void {
    staffBot(['work_chat_buttons_enabled' => false]);
    fakeTelegramSend();
    $order = Order::factory()->order()->create();

    (new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST))->handle(app(TelegramNotifier::class));

    expect(telegramCalls()['sendMessage'][0])->not->toHaveKey('reply_markup');
});

it('writes «Подтвердил Иванов 14:32» under a message after a staff change (TS-03)', function (): void {
    [$staff] = linkedStaff();
    $order = Order::factory()->order()->create();
    app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $staff);

    expect(app(StaffOrderMessage::class)->text($order->refresh()))->toContain('Подтвердил Иванов '.now()->format('H:i'));
});

describe('personal notifications (TS-08)', function (): void {
    it('queues one job per staff member who switched them on, has a linked Telegram and may view requests', function (): void {
        Bus::fake([SendStaffPersonalNotification::class, SendTelegramNotification::class]);
        [$on] = linkedStaff(['orders.view'], 601);
        $on->forceFill(['telegram_personal_notify' => true])->save();
        [$off] = linkedStaff(['orders.view'], 602); // switch off (default)
        [$noRight] = linkedStaff(['products.view'], 603);
        $noRight->forceFill(['telegram_personal_notify' => true])->save();
        [$inactive] = linkedStaff(['orders.view'], 604);
        $inactive->forceFill(['telegram_personal_notify' => true, 'is_active' => false])->save();
        $unlinked = User::factory()->withPermissions(['orders.view'])->create(['telegram_personal_notify' => true]);
        $order = Order::factory()->order()->create();

        (new SendOrderNotifications($order->id, SendOrderNotifications::EVENT_CREATED))->handle(app(NotificationSettings::class), app(TelegramNotifier::class));

        Bus::assertDispatched(SendStaffPersonalNotification::class, 1);
        Bus::assertDispatched(SendStaffPersonalNotification::class, fn ($job) => $job->userId === $on->id && $job->orderId === $order->id);
        expect($off->id)->not->toBe($on->id)->and($unlinked->id)->not->toBe($on->id);
    });

    it('sends the message with the same buttons to the private chat and logs it', function (): void {
        fakeTelegramSend(31, 601);
        [$user, $account] = linkedStaff(['orders.view', 'orders.update'], 601);
        $user->forceFill(['telegram_personal_notify' => true])->save();
        $order = Order::factory()->order()->create();

        (new SendStaffPersonalNotification($order->id, $user->id))->handle(app(StaffOrderNotifier::class));

        $sent = telegramCalls()['sendMessage'][0];
        expect($sent['chat_id'])->toBe('601')
            ->and(buttonsOf($sent['reply_markup']))->toContain('Подтвердить', 'Отменить');

        $log = TelegramMessage::query()->firstOrFail();
        expect($log->telegram_account_id)->toBe($account->id)->and($log->chat_id)->toBe(601)->and($log->message_id)->toBe(31);
    });

    it('checks the right to view requests right before sending', function (): void {
        Http::fake();
        [$user] = linkedStaff(['orders.view'], 601);
        $user->forceFill(['telegram_personal_notify' => true])->save();
        $order = Order::factory()->order()->create();
        // The role lost the right after the job was queued.
        DB::table('role_permissions')->where('role_id', $user->role_id)->delete();
        app(PermissionService::class)->flush();

        (new SendStaffPersonalNotification($order->id, $user->id))->handle(app(StaffOrderNotifier::class));

        Http::assertNothingSent();
        expect(TelegramMessage::query()->count())->toBe(0);
    });

    it('does not write to a staff member whose chat is blocked or who switched the messages off', function (): void {
        Http::fake();
        [$user, $account] = linkedStaff(['orders.view'], 601);
        $order = Order::factory()->order()->create();
        $notifier = app(StaffOrderNotifier::class);

        expect($notifier->sendPersonal($order, $user))->toBeFalse(); // off by default

        $user->forceFill(['telegram_personal_notify' => true])->save();
        $account->forceFill(['bot_blocked_at' => now()])->save();

        expect($notifier->sendPersonal($order, $user->refresh()))->toBeFalse();
        Http::assertNothingSent();
    });
});

describe('/start staff (TS-01)', function (): void {
    it('answers a linked staff member with the web_app button of the workplace', function (): void {
        Http::fake();
        linkedStaff();

        telegramWebhook(tgMessage(1, '/start staff'))
            ->assertOk()
            ->assertJsonPath('method', 'sendMessage')
            ->assertJsonPath('reply_markup.inline_keyboard.0.0.text', 'Рабочее место')
            ->assertJsonPath('reply_markup.inline_keyboard.0.0.web_app.url', rtrim((string) config('app.url'), '/').'/tg?mode=staff');
    });

    it('asks a stranger to link Telegram and never shows the button', function (): void {
        Http::fake();

        $response = telegramWebhook(tgMessage(1, '/start staff'))->assertOk();

        expect($response->json('text'))->toContain('Подключите Telegram')->and($response->json())->not->toHaveKey('reply_markup');
    });

    it('refuses a staff member without orders.view or while the workplace is off', function (): void {
        Http::fake();
        linkedStaff(['products.view']);

        expect(telegramWebhook(tgMessage(1, '/start staff'))->json('text'))->toContain('недоступно');

        User::query()->update(['is_active' => true]);
        [$user] = linkedStaff(['orders.view'], 556);
        staffBot(['staff_workplace_enabled' => false]);

        expect(telegramWebhook(tgMessage(2, '/start staff', 556))->json('text'))->toContain('недоступно')->and($user->id)->toBeInt();
    });

    it('does not queue or send personal messages while the bot is switched off with the token kept (NF-63)', function (): void {
        Bus::fake([SendStaffPersonalNotification::class, SendTelegramNotification::class]);
        Http::fake();
        [$user] = linkedStaff(['orders.view'], 601);
        $user->forceFill(['telegram_personal_notify' => true])->save();
        $order = Order::factory()->order()->create();
        staffBot(['enabled' => false]);

        expect(app(StaffOrderNotifier::class)->personalRecipients())->toHaveCount(0)
            ->and(app(StaffOrderNotifier::class)->sendPersonal($order, $user))->toBeFalse();
        Http::assertNothingSent();
    });
});
