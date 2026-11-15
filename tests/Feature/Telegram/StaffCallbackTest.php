<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_staff.php';

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\StaffOrderAction;
use App\Enums\StatusChangeSource;
use App\Jobs\EditTelegramMessage;
use App\Models\AdminAuditEntry;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\TelegramMessage;
use App\Services\Orders\OrderService;
use App\Services\Telegram\Staff\StaffMessageSync;
use App\Services\Telegram\Staff\StaffOrderActions;
use App\Services\Telegram\Staff\StaffOrderMessage;
use App\Services\Users\PermissionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/**
 * TS-03, TS-04, TS-07: the action buttons of the staff messages (`o:{action}:{order}:{version}`).
 */
beforeEach(function (): void {
    staffBot();
    fakeTelegramApi();
});

function tapButton(Order $order, string $action, array $press = []): TestResponse
{
    static $update = 1000;
    $order->refresh();

    return telegramWebhook(pressStaffButton(++$update, 'o:'.$action.':'.$order->id.':'.$order->version, ...$press));
}

describe('who may press (TS-03)', function (): void {
    it('tells an unknown Telegram user to link Telegram in the admin profile', function (): void {
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf', ['fromId' => 999])
            ->assertOk()
            ->assertJsonPath('method', 'answerCallbackQuery')
            ->assertJsonPath('text', 'Подключите Telegram в профиле админки');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
        expect(telegramCalls())->toBe([]);
    });

    it('answers a deactivated staff member as an unknown one', function (): void {
        [$user] = linkedStaff();
        $user->forceFill(['is_active' => false])->save();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertJsonPath('text', 'Подключите Telegram в профиле админки');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('refuses a message from a foreign group', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf', ['chatId' => -555777])->assertJsonPath('text', 'Кнопка недоступна в этом чате');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('refuses a message from the private chat of somebody else', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf', ['chatId' => 777, 'chatType' => 'private'])->assertJsonPath('text', 'Кнопка недоступна в этом чате');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('allows the own private chat of the staff member (TS-08)', function (): void {
        [$user] = linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf', ['chatId' => 555, 'chatType' => 'private'])->assertJsonPath('text', 'Подтверждено');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)->and($order->updated_by_user_id)->toBe($user->id);
    });

    it('refuses a staff member without orders.update', function (): void {
        linkedStaff(['orders.view']);
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertJsonPath('text', 'Недостаточно прав для этого действия');

        expect($order->refresh()->status)->toBe(OrderStatus::New)
            ->and(OrderStatusHistory::query()->where('source', 'telegram')->count())->toBe(0)
            ->and(telegramCalls())->not->toHaveKey('editMessageText');
    });

    it('re-reads the role on every tap: a removed permission stops the next one at once (TS-02)', function (): void {
        [$user] = linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertJsonPath('text', 'Подтверждено');

        DB::table('role_permissions')->where('role_id', $user->role_id)->delete();
        app(PermissionService::class)->flush();
        Cache::flush();

        tapButton($order, 'rd')->assertJsonPath('text', 'Недостаточно прав для этого действия');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('accepts a tap in the private chat while the work chat switch is off, but not in the work chat (TD-29)', function (): void {
        linkedStaff();
        staffBot(['enabled' => false]);
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertJsonPath('text', 'Кнопки действий отключены в настройках');
        expect($order->refresh()->status)->toBe(OrderStatus::New);

        tapButton($order, 'cf', ['chatId' => 555, 'chatType' => 'private'])->assertJsonPath('text', 'Подтверждено');
        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('does nothing while the buttons are switched off', function (): void {
        linkedStaff();
        staffBot(['work_chat_buttons_enabled' => false]);
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertJsonPath('text', 'Кнопки действий отключены в настройках');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('rejects a malformed callback_data and an unknown order', function (): void {
        linkedStaff();

        telegramWebhook(pressStaffButton(1, 'o:zz:1:1'))->assertJsonPath('text', 'Кнопка устарела');
        telegramWebhook(pressStaffButton(2, 'o:cf:99999:1'))->assertJsonPath('text', 'Обращение не найдено');
    });
});

describe('the actions (TS-03, TS-07)', function (): void {
    it('confirms an order: popup, status, history and audit with the source telegram, the message is redrawn', function (): void {
        [$user] = linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')
            ->assertOk()
            ->assertJsonPath('method', 'answerCallbackQuery')
            ->assertJsonPath('text', 'Подтверждено');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);

        $history = OrderStatusHistory::query()->where('order_id', $order->id)->where('to_status', 'confirmed')->firstOrFail();
        expect($history->source)->toBe(StatusChangeSource::Telegram)->and($history->user_id)->toBe($user->id);

        $audit = AdminAuditEntry::query()->where('action', AuditAction::OrderStatusChanged->value)->firstOrFail();
        expect($audit->source)->toBe(StatusChangeSource::Telegram)
            ->and($audit->user_id)->toBe($user->id)
            ->and($audit->subject_id)->toBe($order->id)
            ->and($audit->data)->toMatchArray(['from' => 'new', 'to' => 'confirmed', 'via' => 'chat']);

        $edit = telegramCalls()['editMessageText'][0];
        expect($edit['chat_id'])->toBe(-1001)
            ->and($edit['message_id'])->toBe(9)
            ->and($edit['parse_mode'])->toBe('HTML')
            ->and($edit['text'])->toContain('Статус: <b>Подтверждён</b>')->toContain('Подтвердил Иванов '.now()->format('H:i'))
            ->and($edit['reply_markup']['inline_keyboard'][0][0]['text'])->toBe('Готов к выдаче');
    });

    it('moves an order to ready and then offers cancel only (TS-01)', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->status(OrderStatus::Confirmed)->create();

        tapButton($order, 'rd')->assertJsonPath('text', 'Отмечено: готов к выдаче');

        expect($order->refresh()->status)->toBe(OrderStatus::Ready);
        $buttons = collect(telegramCalls()['editMessageText'][0]['reply_markup']['inline_keyboard'][0])->pluck('text')->all();
        expect($buttons)->toBe(['Отменить']);
    });

    it('confirms a reservation and extends it by 24 hours counted from the current term', function (): void {
        linkedStaff();
        $until = now()->addHours(5)->startOfMinute();
        $reservation = Order::factory()->reservation()->reservedUntil($until)->create();

        tapButton($reservation, 'cf')->assertJsonPath('text', 'Резерв подтверждён');
        expect($reservation->refresh()->status)->toBe(OrderStatus::Reserved);

        tapButton($reservation, 'ex')->assertJsonPath('text', 'Резерв продлён до '.$until->copy()->addHours(24)->format('d.m.Y H:i'));

        expect($reservation->refresh()->reserved_until->equalTo($until->copy()->addHours(24)))->toBeTrue();
        $history = OrderStatusHistory::query()->where('order_id', $reservation->id)->orderByDesc('id')->first();
        expect($history->source)->toBe(StatusChangeSource::Telegram)->and($history->from_status)->toBe($history->to_status);
        expect(AdminAuditEntry::query()->where('action', AuditAction::OrderReservationExtended->value)->firstOrFail()->source)->toBe(StatusChangeSource::Telegram);
    });

    it('takes an inquiry into work and closes it', function (): void {
        linkedStaff();
        $inquiry = Order::factory()->inquiry()->create();

        tapButton($inquiry, 'ip')->assertJsonPath('text', 'Взято в работу');
        expect($inquiry->refresh()->status)->toBe(OrderStatus::InProgress);

        tapButton($inquiry, 'cl')->assertJsonPath('text', 'Заявка закрыта');
        expect($inquiry->refresh()->status)->toBe(OrderStatus::Closed);
    });

    it('refuses an action that Appendix B does not allow for the status', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create(); // new: «Готов к выдаче» comes after «Подтвердить»

        tapButton($order, 'rd')->assertJsonPath('text', 'Обращение уже изменено');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('returns the stock when a reservation is cancelled with two taps and when an order is cancelled (StockService)', function (): void {
        linkedStaff();
        $product = sellable(['stock_qty' => 10]);
        $reservation = app(OrderService::class)->createReservation($product, reservationData(3));
        expect($product->refresh()->reserved_qty)->toBe(3);

        tapButton($reservation, 'cx');
        tapButton($reservation, 'cx');

        expect($reservation->refresh()->status)->toBe(OrderStatus::Cancelled)->and($product->refresh()->reserved_qty)->toBe(0);

        $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
        expect($product->refresh()->stock_qty)->toBe(9);

        tapButton($order, 'cx');
        tapButton($order, 'cx');

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)->and($product->refresh()->stock_qty)->toBe(10);
    });
});

describe('two taps for the dangerous action (TS-04)', function (): void {
    it('the first tap only asks «Точно отменить?» and the second one inside 30 seconds cancels', function (): void {
        [$user] = linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cx')->assertJsonPath('text', 'Нажмите ещё раз в течение 30 секунд, чтобы отменить');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
        $first = telegramCalls()['editMessageText'][0]['reply_markup']['inline_keyboard'][0];
        expect(collect($first)->pluck('text')->all())->toBe(['Подтвердить', 'Точно отменить?']);
        expect(Cache::has(StaffOrderActions::confirmKey($user, $order, StaffOrderAction::Cancel)))->toBeTrue();

        $this->travel(20)->seconds();
        tapButton($order, 'cx')->assertJsonPath('text', 'Обращение отменено');

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
        expect(OrderStatusHistory::query()->where('to_status', 'cancelled')->where('source', 'telegram')->count())->toBe(1);
    });

    it('a second tap after the window starts the question again and cancels nothing', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cx');
        $this->travel(31)->seconds();
        tapButton($order, 'cx')->assertJsonPath('text', 'Нажмите ещё раз в течение 30 секунд, чтобы отменить');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('the window belongs to the staff member: a colleague\'s first tap does not count', function (): void {
        linkedStaff();
        linkedStaff(['orders.view', 'orders.update'], 556, 'Петров');
        $order = Order::factory()->order()->create();

        tapButton($order, 'cx');
        tapButton($order, 'cx', ['fromId' => 556])->assertJsonPath('text', 'Нажмите ещё раз в течение 30 секунд, чтобы отменить');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('does not take the time from callback_data: a tampered button gets no shortcut', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create();

        telegramWebhook(pressStaffButton(1, 'o:cx:'.$order->id.':1:'.(time() - 5)))->assertJsonPath('text', 'Кнопка устарела');

        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });
});

describe('idempotency by version (TS-03, TS-04)', function (): void {
    it('answers «Обращение уже изменено» and redraws without acting when the version moved', function (): void {
        [$user] = linkedStaff();
        $order = Order::factory()->order()->create();
        $stale = 'o:cf:'.$order->id.':'.$order->refresh()->version;
        // The admin confirmed it meanwhile.
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $user);

        telegramWebhook(pressStaffButton(1, $stale))->assertJsonPath('text', 'Обращение уже изменено');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
            ->and(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(1); // only the admin's change, the stale tap wrote nothing
        expect(telegramCalls()['editMessageText'][0]['text'])->toContain('Статус: <b>Подтверждён</b>');
    });

    it('a repeated tap of the same button changes nothing the second time', function (): void {
        linkedStaff();
        $order = Order::factory()->reservation()->create();
        $data = 'o:ex:'.$order->id.':'.$order->refresh()->version;

        telegramWebhook(pressStaffButton(1, $data))->assertJsonPath('text', fn (string $text) => str_starts_with($text, 'Резерв продлён'));
        $until = $order->refresh()->reserved_until;

        telegramWebhook(pressStaffButton(2, $data))->assertJsonPath('text', 'Обращение уже изменено');

        expect($order->refresh()->reserved_until->equalTo($until))->toBeTrue();
    });
});

describe('the redraw (TS-04, NF-60)', function (): void {
    it('edits the message inside the webhook with a 2 second timeout and queues nothing', function (): void {
        Queue::fake();
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf');

        expect(telegramCalls())->toHaveKey('editMessageText');
        Queue::assertNotPushed(EditTelegramMessage::class);
    });

    it('queues the redraw when the edit fails or times out, the answer is still sent', function (): void {
        Queue::fake();
        Http::swap(new HttpFactory); // drop the stub of beforeEach: the first matching stub wins
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502)]);
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertJsonPath('text', 'Подтверждено');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
        Queue::assertPushed(EditTelegramMessage::class, fn (EditTelegramMessage $job): bool => $job->chatId === '-1001'
            && $job->messageId === 9
            && $job->parseMode === 'HTML'
            && str_contains($job->text, 'Подтверждён'));
    });

    it('queues the redraw when the connection times out inside the webhook', function (): void {
        Queue::fake();
        Http::swap(new HttpFactory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf')->assertOk()->assertJsonPath('text', 'Подтверждено');

        Queue::assertPushed(EditTelegramMessage::class, 1);
    });

    it('does not retry a redraw that Telegram refused for good (the message is gone)', function (): void {
        Queue::fake();
        Http::swap(new HttpFactory);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'], 400)]);
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf');

        Queue::assertNotPushed(EditTelegramMessage::class);
    });

    it('queues the redraw of the other logged copies of the message (the personal chat)', function (): void {
        Queue::fake();
        linkedStaff();
        $order = Order::factory()->order()->create();
        TelegramMessage::factory()->sent()->create(['order_id' => $order->id, 'kind' => 'staff_new_order', 'chat_id' => 555, 'message_id' => 31, 'telegram_account_id' => null]);
        TelegramMessage::factory()->sent()->create(['order_id' => $order->id, 'kind' => 'staff_new_order', 'chat_id' => -1001, 'message_id' => 9, 'telegram_account_id' => null]);

        tapButton($order, 'cf');

        Queue::assertPushed(EditTelegramMessage::class, 1);
        Queue::assertPushed(EditTelegramMessage::class, fn (EditTelegramMessage $job): bool => $job->chatId === '555' && $job->messageId === 31);
    });

    it('the token never reaches a queued redraw job (NF-61)', function (): void {
        Queue::fake();
        Http::swap(new HttpFactory);
        Http::fake(['api.telegram.org/*' => Http::response([], 500)]);
        linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cf');

        Queue::assertPushed(EditTelegramMessage::class, fn (EditTelegramMessage $job): bool => ! str_contains(serialize($job), '123456:TEST-token_abc'));
    });
});

describe('review round 1 fixes', function (): void {
    it('a confirmation armed for an older version cannot make the next single tap cancel (TS-04)', function (): void {
        [$user] = linkedStaff();
        $order = Order::factory()->order()->create();

        tapButton($order, 'cx'); // armed at v1
        $oldVersion = $order->refresh()->version;
        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $user); // a colleague: v2

        telegramWebhook(pressStaffButton(5001, 'o:cx:'.$order->id.':'.$oldVersion))->assertJsonPath('text', 'Обращение уже изменено');
        tapButton($order, 'cx')->assertJsonPath('text', 'Нажмите ещё раз в течение 30 секунд, чтобы отменить');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('refuses the workplace-only codes from a chat button (TS-01 lists no such buttons)', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->status(OrderStatus::Confirmed)->create();

        Queue::fake();
        tapButton($order, 'sh')->assertJsonPath('text', __('telegram_staff.toast.bad_data'));
        tapButton($order, 'co')->assertJsonPath('text', __('telegram_staff.toast.bad_data'));
        Queue::assertNothingPushed();

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
        expect(array_map(fn ($a) => $a->value, app(StaffOrderActions::class)->available($order)))->toBe(['rd', 'cx']);
    });

    it('offers shipped and completed to the workplace only', function (): void {
        $order = Order::factory()->order()->status(OrderStatus::Confirmed)->create();
        $reservation = Order::factory()->reservation()->status(OrderStatus::Reserved)->create();
        $actions = app(StaffOrderActions::class);

        expect(array_map(fn ($a) => $a->value, $actions->available($order, workplace: true)))->toBe(['rd', 'sh', 'cx'])
            ->and(array_map(fn ($a) => $a->value, $actions->available($reservation, workplace: true)))->toBe(['ex', 'co', 'cx']);
    });

    it('does not accept the staff buttons while the bot is not connected, although the token is kept (NF-63)', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create();
        staffBot(['connected_at' => null]);

        tapButton($order, 'cf')->assertOk();

        expect($order->refresh()->status)->toBe(OrderStatus::New);
        expect(app(StaffOrderMessage::class)->markup($order))->toBeNull();
    });

    it('skips the redraw of a personal copy of a staff member who unlinked or lost orders.view', function (): void {
        Queue::fake();
        [$user, $account] = linkedStaff(['orders.view'], 601);
        [$gone, $goneAccount] = linkedStaff(['orders.view'], 602);
        [$noRight, $noRightAccount] = linkedStaff(['products.view'], 603);
        $goneAccount->forceFill(['user_id' => null])->save();
        $order = Order::factory()->order()->create();

        foreach ([[$account, 31], [$goneAccount, 32], [$noRightAccount, 33]] as [$acc, $mid]) {
            TelegramMessage::factory()->sent()->create(['order_id' => $order->id, 'kind' => 'staff_new_order', 'chat_id' => $acc->telegram_user_id, 'message_id' => $mid, 'telegram_account_id' => $acc->id]);
        }

        app(StaffMessageSync::class)->redrawCopies($order);

        Queue::assertPushed(EditTelegramMessage::class, 1);
        Queue::assertPushed(EditTelegramMessage::class, fn (EditTelegramMessage $job): bool => $job->messageId === 31);
        expect([$user->id, $gone->id, $noRight->id])->each->toBeInt();
    });
});
