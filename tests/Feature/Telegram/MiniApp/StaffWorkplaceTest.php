<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';
require_once __DIR__.'/../../../Support/telegram_staff.php';

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Livewire\Staff\OrderCard;
use App\Livewire\Staff\WorkplaceList;
use App\Models\AdminAuditEntry;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Users\PermissionService;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use App\Support\TelegramMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * TS-05, TS-06, TS-07: the entry of the staff mode, the guard `telegram_staff` and its isolation from the admin, the
 * conditions checked on every request, the workplace screens, the rights and the SQL budget.
 */
beforeEach(function (): void {
    $this->withoutVite();
    staffBot();
});

function staffEntry(array $extra = [], array $post = [], int $userId = 555, ?int $authDate = null): TestResponse
{
    return test()->postJson('/tg/session', ['initData' => tgInitData($extra, ['id' => $userId, 'first_name' => 'Ivan', 'username' => 'ivan_tg'], $authDate)] + $post);
}

describe('the entry (TS-05)', function (): void {
    it('opens the workplace from a signed start_param staff and signs in to telegram_staff only', function (): void {
        [$user] = linkedStaff();

        staffEntry(['start_param' => 'staff'])
            ->assertOk()
            ->assertJson(['url' => '/tg/staff'])
            ->assertSessionHas(TelegramMode::STAFF_KEY, true);

        expect(Auth::guard('telegram_staff')->id())->toBe($user->id)
            ->and(Auth::guard('web')->check())->toBeFalse()
            ->and(session()->has(TelegramMode::ACCOUNT_KEY))->toBeFalse();
    });

    it('opens the card of the request from staff_o_{id}', function (): void {
        linkedStaff();
        $order = Order::factory()->order()->create();

        staffEntry(['start_param' => 'staff_o_'.$order->id])->assertOk()->assertJson(['url' => '/tg/staff/orders/'.$order->id]);
    });

    it('takes the mode=staff hint of the web_app button when nothing signed came', function (): void {
        linkedStaff();

        staffEntry([], ['mode' => 'staff'])->assertOk()->assertJson(['url' => '/tg/staff']);
    });

    it('never takes staff mode from the unsigned to-hint or from a mode hint next to another signed parameter', function (): void {
        linkedStaff();

        staffEntry([], ['to' => 'staff'])->assertOk()->assertJson(['url' => '/tg/start']);
        expect(Auth::guard('telegram_staff')->check())->toBeFalse();

        staffEntry(['start_param' => 'cart'], ['mode' => 'staff'])->assertOk();
        expect(Auth::guard('telegram_staff')->check())->toBeFalse();
    });

    it('falls back to the customer entry for each failed condition and tells nothing about the link', function (string $case): void {
        [$user, $account] = linkedStaff();
        $authDate = null;

        match ($case) {
            'inactive' => $user->forceFill(['is_active' => false])->save(),
            'no_view' => DB::table('role_permissions')->where('role_id', $user->role_id)->delete(),
            'unlinked' => $account->forceFill(['user_id' => null])->save(),
            'feature_off' => staffBot(['staff_workplace_enabled' => false]),
            'miniapp_off' => staffBot(['miniapp_enabled' => false]),
            'expired' => $authDate = time() - 4000 - (int) app(TelegramSettings::class)->initdata_ttl_staff,
            'foreign' => $user->forceFill([])->save(),
        };

        $response = staffEntry(['start_param' => 'staff'], [], $case === 'foreign' ? 999 : 555, $authDate);

        expect(Auth::guard('telegram_staff')->check())->toBeFalse()->and(session()->has(TelegramMode::STAFF_KEY))->toBeFalse();

        // The customer entry answers (or the whole Mini App is off): never a staff address.
        expect((string) $response->getContent())->not->toContain('/tg/staff');
    })->with(['inactive', 'no_view', 'unlinked', 'feature_off', 'miniapp_off', 'expired', 'foreign']);

    it('refuses a forged signature', function (): void {
        linkedStaff();

        $this->postJson('/tg/session', ['initData' => tgInitData(['start_param' => 'staff'], token: '999:OTHER')])->assertForbidden();

        expect(Auth::guard('telegram_staff')->check())->toBeFalse();
    });

    it('sends a visitor without a staff session back to the entry', function (): void {
        $this->get('/tg/staff')->assertRedirect('/tg?mode=staff');
        $this->get('/tg/staff/orders/1')->assertRedirect('/tg?mode=staff');
    });

    it('carries the mode hint of the web_app button from /tg to POST /tg/session', function (): void {
        $this->get('/tg?mode=staff')->assertOk()->assertSee("query.get('mode')", false);
    });
});

describe('guard isolation (TS-05)', function (): void {
    it('staff entry, then customer entry in the same session: the admin and auth:web routes stay closed', function (): void {
        [$user] = linkedStaff(['orders.view', 'orders.update', 'orders.export']);
        $order = Order::factory()->order()->create();

        staffEntry(['start_param' => 'staff'])->assertOk();
        $this->get('/tg/staff')->assertOk();

        // The staff sign-in is not an admin sign-in.
        $this->get('/admin')->assertRedirect();
        $this->get(route('admin.orders.print', $order))->assertRedirect();
        expect(Auth::guard('web')->check())->toBeFalse();

        // The customer entry of the same Telegram user in the same session ends the staff sign-in.
        staffEntry([])->assertOk();

        expect(Auth::guard('telegram_staff')->check())->toBeFalse()->and(session()->has(TelegramMode::STAFF_KEY))->toBeFalse();
        $this->get('/tg/staff')->assertRedirect('/tg?mode=staff');
        $this->get('/admin')->assertRedirect();
        $this->get(route('admin.orders.print', $order))->assertRedirect();
        $this->get(route('admin.orders.pdf', $order))->assertRedirect();
        expect($user->id)->toBeInt();
    });

    it('does not turn the telegram_staff session into an admin session: auth:web routes redirect even with a live staff session', function (): void {
        [$user] = linkedStaff(['orders.view', 'orders.update']);
        $order = Order::factory()->order()->create();

        $this->withSession(staffSession($user))->get(route('admin.orders.print', $order))->assertRedirect();
        $this->withSession(staffSession($user))->get('/admin/orders')->assertRedirect();
    });

    it('restores the default guard after a /tg/staff request', function (): void {
        [$user] = linkedStaff();

        $this->withSession(staffSession($user))->get('/tg/staff')->assertOk();

        expect(Auth::getDefaultDriver())->toBe('web');
    });

    it('a customer entry of another Telegram user empties the whole session', function (): void {
        [$user] = linkedStaff();

        staffEntry(['start_param' => 'staff'])->assertOk();
        staffEntry([], [], 777)->assertOk();

        expect(Auth::guard('telegram_staff')->check())->toBeFalse();
        $this->get('/tg/staff')->assertRedirect('/tg?mode=staff');
        expect($user->id)->toBeInt();
    });

    it('«режим Telegram снят» (POST /tg/leave) ends the staff sign-in', function (): void {
        linkedStaff();

        staffEntry(['start_param' => 'staff'])->assertOk();
        $this->postJson('/tg/leave')->assertOk();

        $this->get('/tg/staff')->assertRedirect('/tg?mode=staff');
    });
});

describe('access is read on every request (TS-02)', function (): void {
    it('refuses a deactivated user, an unlinked Telegram, a lost right and a switched-off feature at once', function (string $case): void {
        [$user, $account] = linkedStaff();
        $session = staffSession($user);
        $this->withSession($session)->get('/tg/staff')->assertOk();

        match ($case) {
            'inactive' => $user->forceFill(['is_active' => false])->save(),
            'unlinked' => $account->forceFill(['user_id' => null])->save(),
            'right' => DB::table('role_permissions')->where('role_id', $user->role_id)->delete(),
            'feature' => staffBot(['staff_workplace_enabled' => false]),
            'other_telegram' => $account->forceFill(['telegram_user_id' => 12345])->save(),
        };
        app(PermissionService::class)->flush();
        Cache::flush();
        Auth::guard('telegram_staff')->forgetUser(); // a real request starts without the user of the previous one

        $this->withSession($session)->get('/tg/staff')->assertForbidden();
        $this->withSession($session)->get('/tg/staff/orders/1')->assertForbidden();
    })->with(['inactive', 'unlinked', 'right', 'feature', 'other_telegram']);

    it('ends the staff session after eight hours (NF-12)', function (): void {
        [$user] = linkedStaff();

        $this->withSession(staffSession($user, 555, time() - 8 * 3600 - 5))->get('/tg/staff')->assertForbidden();
        $this->withSession(staffSession($user, 555, time() - 7 * 3600))->get('/tg/staff')->assertOk();
    });

    it('works while the shop is closed', function (): void {
        [$user] = linkedStaff();
        $general = app(GeneralSettings::class);
        $general->shop_open = false;
        $general->save();

        $this->withSession(staffSession($user))->get('/tg/staff')->assertOk();
    });
});

describe('the workplace (TS-06)', function (): void {
    beforeEach(function (): void {
        [$this->staff] = linkedStaff();
        session(staffSession($this->staff));
    });

    it('lists new, in-work, expiring-today and all requests in the tabs', function (): void {
        $new = Order::factory()->order()->create(['customer_name' => 'Новый Клиент']);
        $work = Order::factory()->order()->status(OrderStatus::Confirmed)->create(['customer_name' => 'Рабочий Клиент']);
        $today = Order::factory()->reservation()->reservedUntil(now()->addMinutes(30))->create(['customer_name' => 'Резерв Сегодня']);
        $later = Order::factory()->reservation()->reservedUntil(now()->addDays(3)->endOfDay()->addHour())->create(['customer_name' => 'Резерв Потом']);
        $done = Order::factory()->order()->status(OrderStatus::Completed)->create(['customer_name' => 'Завершённый']);

        Livewire::test(WorkplaceList::class)
            ->assertSee('Новый Клиент')->assertSee('Резерв Сегодня')->assertDontSee('Рабочий Клиент')
            ->call('setTab', 'active')->assertSee('Рабочий Клиент')->assertDontSee('Завершённый')
            ->call('setTab', 'expiring')->assertSee('Резерв Сегодня')->assertDontSee('Резерв Потом')->assertDontSee('Рабочий Клиент')
            ->call('setTab', 'all')->assertSee('Завершённый')->assertSee('Резерв Потом')->assertSee('Рабочий Клиент');

        expect([$new->id, $work->id, $today->id, $later->id, $done->id])->each->toBeInt();
    });

    it('searches by number, phone and name', function (): void {
        $a = Order::factory()->order()->create(['customer_name' => 'Анатолий Звонарёв', 'customer_phone' => '+79001112233']);
        $b = Order::factory()->order()->create(['customer_name' => 'Борис Другой', 'customer_phone' => '+79004445566']);

        Livewire::test(WorkplaceList::class)->set('tab', 'all')
            ->set('search', 'Звонар')->assertSee('Анатолий')->assertDontSee('Борис')
            ->set('search', '8 (900) 444-55-66')->assertSee('Борис')->assertDontSee('Анатолий')
            ->set('search', '1112')->assertSee('Анатолий')->assertDontSee('Борис')
            ->set('search', $b->number)->assertSee('Борис')->assertDontSee('Анатолий');

        expect($a->id)->not->toBe($b->id);
    });

    it('shows the card: items, sum, customer, call link, comment, history, Telegram messages', function (): void {
        $order = Order::factory()->order()->withItems(2)->create(['customer_comment' => 'Позвоните после шести', 'customer_phone' => '+79001112233']);
        TelegramMessage::factory()->sent()->create(['order_id' => $order->id, 'kind' => 'status_changed']);

        $html = Livewire::test(OrderCard::class, ['orderId' => $order->id])
            ->assertSee('Позвоните после шести')
            ->assertSee('Смена статуса')
            ->assertSee($order->items->first()->displayName())
            ->html();

        expect($html)->toContain('href="tel:+79001112233"')->toContain('data-staff-admin')->toContain('data-staff-history');
    });

    it('changes the status through the same service: history and audit carry the source telegram', function (): void {
        $order = Order::factory()->order()->create();

        Livewire::test(OrderCard::class, ['orderId' => $order->id])
            ->assertSee('data-action="cf"', false)
            ->call('act', 'cf')
            ->assertSee('Подтверждён');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
        expect(OrderStatusHistory::query()->where('order_id', $order->id)->firstOrFail()->source->value)->toBe('telegram');
        $audit = AdminAuditEntry::query()->where('action', AuditAction::OrderStatusChanged->value)->firstOrFail();
        expect($audit->source->value)->toBe('telegram')->and($audit->data['via'])->toBe('workplace');
    });

    it('cancels in two clicks (TS-04)', function (): void {
        $order = Order::factory()->order()->create();
        $card = Livewire::test(OrderCard::class, ['orderId' => $order->id]);

        $card->call('act', 'cx')->assertSee('Точно отменить?');
        expect($order->refresh()->status)->toBe(OrderStatus::New);

        $card->call('act', 'cx');
        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('extends a reservation by the typed hours and saves the manager comment', function (): void {
        $until = now()->addHours(2)->startOfMinute();
        $order = Order::factory()->reservation()->reservedUntil($until)->create();

        Livewire::test(OrderCard::class, ['orderId' => $order->id])
            ->set('extendHours', 48)->call('act', 'ex')
            ->set('managerComment', 'Клиент заберёт в пятницу')->call('saveComment')
            ->assertSee('Комментарий сохранён');

        expect($order->refresh()->reserved_until->equalTo($until->copy()->addHours(48)))->toBeTrue()
            ->and($order->manager_comment)->toBe('Клиент заберёт в пятницу');
        expect(AdminAuditEntry::query()->where('action', AuditAction::OrderReservationExtended->value)->firstOrFail()->data['hours'])->toBe(48);
        expect(AdminAuditEntry::query()->where('action', AuditAction::OrderManagerCommentChanged->value)->firstOrFail()->source->value)->toBe('telegram');
    });

    it('does nothing when the request changed since the card was drawn', function (): void {
        $order = Order::factory()->order()->create();
        $card = Livewire::test(OrderCard::class, ['orderId' => $order->id]);

        app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed, $this->staff);
        $card->call('act', 'cx')->assertSee('Обращение уже изменено');

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('offers no actions, no editing and a read-only comment to a staff member with orders.view only', function (): void {
        [$viewer] = linkedStaff(['orders.view'], 556, 'Смотрящий');
        session(staffSession($viewer, 556));
        $order = Order::factory()->order()->create();

        $html = Livewire::test(OrderCard::class, ['orderId' => $order->id])
            ->assertSee('только просмотр')
            ->call('act', 'cf')
            ->assertSee('Недостаточно прав для этого действия')
            ->html();

        expect($order->refresh()->status)->toBe(OrderStatus::New)
            ->and($html)->not->toContain('data-action="cf"')->not->toContain('data-manager-comment');

        Livewire::test(OrderCard::class, ['orderId' => $order->id])->set('managerComment', 'x')->call('saveComment')->assertSee('Недостаточно прав');
        expect($order->refresh()->manager_comment)->toBeNull();
    });

    it('has no item editing, prices, deletion or export in the workplace', function (): void {
        $order = Order::factory()->order()->withItems(1)->create();

        $html = Livewire::test(OrderCard::class, ['orderId' => $order->id])->html();

        expect($html)->not->toContain('Удалить')->not->toContain('Экспорт')->not->toContain('Редактировать состав');
    });

    it('marks a reserved reservation «Выкуплен»: stock written off through the service, audit source telegram (TS-06)', function (): void {
        $product = sellable(['stock_qty' => 10]);
        $reservation = app(OrderService::class)->createReservation($product, reservationData(3));
        app(OrderService::class)->changeStatus($reservation, OrderStatus::Reserved, $this->staff);
        expect($product->refresh()->reserved_qty)->toBe(3);

        Livewire::test(OrderCard::class, ['orderId' => $reservation->id])
            ->assertSee('data-action="co"', false)->assertSee('Выкуплен')
            ->call('act', 'co');

        expect($reservation->refresh()->status)->toBe(OrderStatus::Completed)
            ->and($product->refresh()->reserved_qty)->toBe(0)->and($product->stock_qty)->toBe(7);
        $audit = AdminAuditEntry::query()->where('action', AuditAction::OrderStatusChanged->value)->latest('id')->firstOrFail();
        expect($audit->source->value)->toBe('telegram')->and($audit->data['to'])->toBe('completed');
        expect(OrderStatusHistory::query()->where('order_id', $reservation->id)->where('to_status', 'completed')->firstOrFail()->source->value)->toBe('telegram');
    });

    it('ships a confirmed order and completes it; a ready order is completed directly (TS-06)', function (): void {
        $confirmed = Order::factory()->order()->status(OrderStatus::Confirmed)->create();
        $card = Livewire::test(OrderCard::class, ['orderId' => $confirmed->id]);

        $card->assertSee('data-action="sh"', false)->call('act', 'sh');
        expect($confirmed->refresh()->status)->toBe(OrderStatus::Shipped);

        $card->assertSee('Выполнен')->call('act', 'co');
        expect($confirmed->refresh()->status)->toBe(OrderStatus::Completed);

        $ready = Order::factory()->order()->status(OrderStatus::Ready)->create();
        Livewire::test(OrderCard::class, ['orderId' => $ready->id])->call('act', 'co');
        expect($ready->refresh()->status)->toBe(OrderStatus::Completed);
    });

    it('refuses completed for a status the matrix does not allow', function (): void {
        $new = Order::factory()->order()->create();

        Livewire::test(OrderCard::class, ['orderId' => $new->id])->call('act', 'co')->assertSee('Обращение уже изменено');
        expect($new->refresh()->status)->toBe(OrderStatus::New);
    });

    it('refuses shipped and completed without orders.update', function (): void {
        $order = Order::factory()->order()->status(OrderStatus::Confirmed)->create();

        [$viewer] = linkedStaff(['orders.view'], 556, 'Смотрящий');
        session(staffSession($viewer, 556));
        Livewire::test(OrderCard::class, ['orderId' => $order->id])->call('act', 'sh')->assertSee('Недостаточно прав');
        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('locks the page size: a client cannot set it (it would load the whole table)', function (): void {
        Order::factory()->order()->count(3)->create();

        expect(fn () => Livewire::test(WorkplaceList::class)->set('limit', 100000))->toThrow(Exception::class, 'locked property');
    });

    it('clamps the page size at the maximum however often «Показать ещё» is pressed', function (): void {
        Order::factory()->order()->count(3)->create();

        $list = Livewire::test(WorkplaceList::class);
        for ($i = 0; $i < 10; $i++) {
            $list->call('more');
        }
        expect($list->get('limit'))->toBe(WorkplaceList::MAX_LIMIT);
    });

    it('takes the staff member explicitly: the default guard is not telegram_staff inside an action', function (): void {
        $order = Order::factory()->order()->create();

        // No default-guard user exists, yet the action works: the user comes from StaffSessionService, not from auth().
        expect(Auth::guard('web')->check())->toBeFalse();
        Livewire::test(OrderCard::class, ['orderId' => $order->id])->call('act', 'cf');
        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);

        // Pinned in the source: no default-guard lookups in the workplace code.
        $files = array_merge(glob(app_path('Livewire/Staff/*.php')) ?: [], glob(app_path('Livewire/Staff/Concerns/*.php')) ?: [], [app_path('Services/Telegram/Staff/StaffOrderActions.php')]);
        foreach ($files as $file) {
            expect(file_get_contents($file))->not->toMatch('/\bauth\(\)->(user|id|check)\(|Auth::(user|id|check)\(|allowsCurrent/');
        }
    });

    it('refuses a Livewire action once the staff member lost access (NF-54)', function (): void {
        $order = Order::factory()->order()->create();
        $card = Livewire::test(OrderCard::class, ['orderId' => $order->id]);

        $this->staff->forceFill(['is_active' => false])->save();
        Auth::guard('telegram_staff')->forgetUser(); // the next Livewire request loads the user anew

        $card->call('act', 'cf')->assertForbidden();
        expect($order->refresh()->status)->toBe(OrderStatus::New);
    });

    it('refuses the components without a staff session', function (): void {
        session()->flush();

        Livewire::test(WorkplaceList::class)->assertForbidden();
    });

    it('keeps the list and the card within 30 SQL statements (NF-02)', function (): void {
        Order::factory()->order()->count(25)->create();
        $order = Order::factory()->order()->withItems(3)->create();
        $session = staffSession($this->staff);

        foreach (['/tg/staff', '/tg/staff/orders/'.$order->id] as $url) {
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });

            $this->withSession($session)->get($url)->assertOk();

            expect(count($queries))->toBeLessThanOrEqual(30, $url.': '.count($queries).' queries');
        }
    });

    it('writes no theme-breaking colour classes: the workplace uses tokens only (TH-06)', function (): void {
        $source = file_get_contents(resource_path('views/livewire/staff/workplace-list.blade.php')).file_get_contents(resource_path('views/livewire/staff/order-card.blade.php'));

        expect(preg_match('/\b(?:bg|text|border|ring|divide)-(?:red|gray|slate|blue|green|amber|yellow|zinc|neutral|stone|white|black)\b/', $source))->toBe(0)
            ->and(preg_match('/#[0-9a-fA-F]{3,8}\b/', $source))->toBe(0);
    });
});
