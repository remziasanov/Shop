<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\SalesException;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\Orders\NewRequestsCounter;
use App\Services\Orders\OrderService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48]);
    Cache::flush();
});

function staff(string $role = 'admin'): User
{
    return User::factory()->{$role}()->create();
}

// ------------------------------------------------------------------ list (OR-01, OR-02)

it('renders the list for an administrator and a manager with the tabs and new counters', function (string $role): void {
    actingAs(staff($role));
    Order::factory()->count(2)->create();
    Order::factory()->reservation()->create();
    Order::factory()->inquiry()->status(OrderStatus::Closed)->create();

    $this->get(OrderResource::getUrl('index'))->assertOk()->assertSee('Заказы')->assertSee('Резервы')->assertSee('Заявки');

    $tabs = Livewire::test(ListOrders::class)->instance()->getCachedTabs();

    expect(array_keys($tabs))->toBe(['all', 'order', 'reservation', 'inquiry'])
        ->and($tabs['all']->getBadge())->toEqual(3)
        ->and($tabs['order']->getBadge())->toEqual(2)
        ->and($tabs['reservation']->getBadge())->toEqual(1)
        ->and($tabs['inquiry']->getBadge())->toBeNull();
})->with(['admin', 'manager']);

it('limits the list by tab', function (): void {
    actingAs(staff());
    $order = Order::factory()->create();
    $reservation = Order::factory()->reservation()->create();

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order, $reservation])
        ->set('activeTab', 'reservation')
        ->assertCanSeeTableRecords([$reservation])
        ->assertCanNotSeeTableRecords([$order]);
});

it('filters by type, status, period and delivery method', function (): void {
    actingAs(staff());
    $old = Order::factory()->create(['created_at' => '2026-01-10 12:00:00', 'delivery_method' => 'Самовывоз']);
    $new = Order::factory()->status(OrderStatus::Confirmed)->create(['created_at' => '2026-03-10 12:00:00', 'delivery_method' => 'Курьер']);
    $reservation = Order::factory()->reservation()->create(['created_at' => '2026-03-11 12:00:00']);

    Livewire::test(ListOrders::class)
        ->filterTable('type', 'reservation')->assertCanSeeTableRecords([$reservation])->assertCanNotSeeTableRecords([$old, $new])
        ->removeTableFilters()
        ->filterTable('status', 'confirmed')->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old, $reservation])
        ->removeTableFilters()
        ->filterTable('period', ['created_from' => '2026-03-10', 'created_until' => '2026-03-10'])->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old, $reservation])
        ->removeTableFilters()
        ->filterTable('delivery_method', 'Самовывоз')->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$new, $reservation]);
});

it('searches by number, phone in any format, name and email', function (): void {
    actingAs(staff());
    $a = Order::factory()->create(['number' => 'Z-00042', 'customer_name' => 'Пётр Иванов', 'customer_phone' => '+79001234567', 'customer_email' => 'petr@example.com']);
    $b = Order::factory()->create(['number' => 'Z-00043', 'customer_name' => 'Мария', 'customer_phone' => '+79117654321', 'customer_email' => null]);

    foreach (['Z-00042', '8 (900) 123-45-67', '+7 900 1234567', '9001234567', '123-45-67', 'Пётр', 'petr@example'] as $term) {
        Livewire::test(ListOrders::class)->searchTable($term)
            ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }

    Livewire::test(ListOrders::class)->searchTable('8 911 765 43 21')
        ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
});

it('does not run a query per row on the list', function (): void {
    actingAs(staff());
    Order::factory()->count(12)->withItems(2)->create();

    $component = Livewire::test(ListOrders::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call('$refresh');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(30);
});

it('shows the reservation term in red when less than 6 hours are left', function (): void {
    actingAs(staff());
    $soon = Order::factory()->reservation()->reservedUntil(now()->addHours(3))->create();
    $later = Order::factory()->reservation()->reservedUntil(now()->addHours(30))->create();

    expect(OrdersTable::isUrgent($soon))->toBeTrue()
        ->and(OrdersTable::isUrgent($later))->toBeFalse();
});

// ------------------------------------------------------------------ view (OR-03)

it('shows the request card with items, history, consent and contact links', function (): void {
    actingAs(staff());
    $product = sellable(['name' => 'Кресло А', 'sku' => 'KR-1']);
    $order = Order::factory()->create([
        'customer_phone' => '+79001234567',
        'customer_comment' => 'Позвоните после 18',
        'manager_comment' => 'Клиент постоянный',
        'consent_ip' => '203.0.113.7',
        'delivery_method' => 'Курьерская доставка',
        'delivery_address' => 'г. Тестоград, ул. Примерная, 1',
        'payment_method' => 'Наличными',
    ]);
    OrderItem::factory()->forProduct($product, 2)->create(['order_id' => $order->id]);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'from_status' => null, 'to_status' => OrderStatus::New, 'user_id' => null, 'created_at' => now()]);
    $manager = User::factory()->manager()->create(['name' => 'Анна Менеджер']);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'from_status' => OrderStatus::New, 'to_status' => OrderStatus::Confirmed, 'user_id' => $manager->id, 'comment' => 'Созвонились', 'created_at' => now()]);

    $this->get(OrderResource::getUrl('view', ['record' => $order]))
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee('KR-1')
        ->assertSee('Кресло А')
        ->assertSee(route('filament.admin.resources.products.edit', $product), false)
        ->assertSee('tel:+79001234567', false)
        ->assertSee('https://wa.me/79001234567', false)
        ->assertSee('https://t.me/+79001234567', false)
        ->assertSee('203.0.113.7')
        ->assertSee('Позвоните после 18')
        ->assertSee('Клиент постоянный')
        ->assertSee('Курьерская доставка')
        ->assertSee('Анна Менеджер')
        ->assertSee('Созвонились')
        ->assertSee('Система / покупатель');
});

it('lets staff edit the manager comment', function (string $role): void {
    actingAs(staff($role));
    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('manager_comment', ['manager_comment' => 'Перезвонить завтра'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->manager_comment)->toBe('Перезвонить завтра');
})->with(['admin', 'manager']);

// ------------------------------------------------------------------ status change (OR-04)

it('accepts only the allowed statuses (Appendix B)', function (): void {
    actingAs(staff());
    $order = Order::factory()->create();

    foreach (OrderType::Order->statuses() as $status) {
        $allowed = in_array($status, [OrderStatus::Confirmed, OrderStatus::Cancelled], true);
        $test = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->callAction('change_status', ['status' => $status->value]);

        if (! $allowed) {
            $test->assertHasActionErrors(['status']);

            continue;
        }

        $test->assertHasNoActionErrors();
        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::New->value]);
    }
});

it('rejects a status outside the allowed list', function (): void {
    actingAs(staff());
    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('change_status', ['status' => 'completed'])
        ->assertHasActionErrors(['status']);

    expect($order->fresh()->status)->toBe(OrderStatus::New);
});

it('hides the status action for a request in a final status', function (): void {
    actingAs(staff());
    $order = Order::factory()->status(OrderStatus::Completed)->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('change_status');
});

it('changes the status with a comment and records the user in the history', function (): void {
    $user = staff('manager');
    actingAs($user);
    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('change_status', ['status' => 'confirmed', 'comment' => 'Проверили наличие'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $history = $order->fresh()->statusHistory->last();

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($history->user_id)->toBe($user->id)
        ->and($history->comment)->toBe('Проверили наличие');
});

it('returns the stock when an order is cancelled', function (): void {
    actingAs(staff());
    $product = sellable(['stock_qty' => 10]);
    $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    expect($product->fresh()->stock_qty)->toBe(9);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('change_status', ['status' => 'cancelled'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->stock_qty)->toBe(10);
});

it('reduces stock and reserved quantity when a reservation is completed', function (): void {
    actingAs(staff());
    $product = sellable(['stock_qty' => 10]);
    $order = app(OrderService::class)->createReservation($product, reservationData(2));
    expect($product->fresh()->reserved_qty)->toBe(2);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('change_status', ['status' => 'completed'])
        ->assertHasNoActionErrors();

    $product->refresh();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($product->stock_qty)->toBe(8)
        ->and($product->reserved_qty)->toBe(0);
});

it('sets a new term when a reservation is confirmed', function (): void {
    actingAs(staff());
    $order = app(OrderService::class)->createReservation(sellable(), reservationData());
    $until = now()->addDays(5)->startOfMinute();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('change_status', ['status' => 'reserved', 'reserved_until' => $until->format('Y-m-d H:i:s')])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Reserved)
        ->and($order->fresh()->reserved_until->equalTo($until))->toBeTrue();
});

it('shows a notification instead of failing when the service refuses the change', function (): void {
    actingAs(staff());
    $order = Order::factory()->create();
    // Any SalesException thrown inside the service transaction must end up as a notification.
    Event::listen('eloquent.saving: '.Order::class, fn () => throw new SalesException('Переход недопустим'));

    try {
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('change_status', ['status' => 'confirmed'])
            ->assertNotified();
    } finally {
        Event::forget('eloquent.saving: '.Order::class);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::New);
});

// ------------------------------------------------------------------ extend (OR-05)

it('extends a reservation by the given hours, default from settings', function (): void {
    actingAs(staff());
    $order = Order::factory()->reservation()->reservedUntil(now()->addHours(10))->create();
    $before = $order->reserved_until;

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('extend')
        ->assertActionDataSet(['hours' => 48])
        ->setActionData(['hours' => 12])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($order->fresh()->reserved_until->equalTo($before->copy()->addHours(12)))->toBeTrue();
});

it('offers the extension only for active reservations', function (): void {
    actingAs(staff());
    $order = Order::factory()->create();
    $done = Order::factory()->reservation()->status(OrderStatus::Expired)->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('extend');
    Livewire::test(ViewOrder::class, ['record' => $done->getRouteKey()])->assertActionHidden('extend');
});

// ------------------------------------------------------------------ print (OR-06)

it('serves the print blank to staff and redirects guests to the login', function (): void {
    $order = Order::factory()->withItems(2)->create();

    $this->get(route('admin.orders.print', $order))->assertRedirect();

    actingAs(staff('manager'));
    $this->get(route('admin.orders.print', $order))
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee('window.print()', false);
});

// ------------------------------------------------------------------ delete (OR-07)

it('lets only an administrator delete', function (): void {
    $order = Order::factory()->create();
    $manager = staff('manager');
    $admin = staff();

    expect(Gate::forUser($manager)->allows('delete', $order))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('deleteAny', Order::class))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('forceDelete', $order))->toBeFalse()
        ->and(Gate::forUser($manager)->allows('update', $order))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $order))->toBeTrue();

    actingAs($manager);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('delete');
    expect(OrderResource::canDelete($order))->toBeFalse();
    expect(fn () => Gate::authorize('delete', $order))->toThrow(AuthorizationException::class);
    Livewire::test(ListOrders::class)->assertTableBulkActionHidden('delete');
    expect(Order::query()->count())->toBe(1);
});

it('lets an administrator soft delete a request from its card', function (): void {
    actingAs(staff());
    $order = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionVisible('delete')->callAction('delete');

    expect(Order::query()->count())->toBe(0)->and(Order::withTrashed()->count())->toBe(1);
});

it('lets an administrator delete in bulk', function (): void {
    actingAs(staff());
    $orders = Order::factory()->count(2)->create();

    Livewire::test(ListOrders::class)->callTableBulkAction('delete', $orders);

    expect(Order::query()->count())->toBe(0);
});

it('denies inactive users and guests', function (): void {
    $order = Order::factory()->create();
    actingAs(User::factory()->manager()->inactive()->create());

    expect(Gate::allows('view', $order))->toBeFalse()->and(Gate::allows('viewAny', Order::class))->toBeFalse();
});

// ------------------------------------------------------------------ navigation badge, global search (AD-04)

it('shows the count of new requests of all types on the menu item and caches it briefly', function (): void {
    actingAs(staff());
    Order::factory()->create();
    Order::factory()->reservation()->create();
    Order::factory()->inquiry()->create();
    Order::factory()->status(OrderStatus::Confirmed)->create();

    expect(OrderResource::getNavigationBadge())->toBe('3');

    Order::factory()->create();
    expect(OrderResource::getNavigationBadge())->toBe('3'); // cached

    app(NewRequestsCounter::class)->forget();
    expect(OrderResource::getNavigationBadge())->toBe('4');

    Order::query()->update(['status' => OrderStatus::Closed]);
    app(NewRequestsCounter::class)->forget();
    expect(OrderResource::getNavigationBadge())->toBeNull();
});

it('groups the resource under «Продажи»', function (): void {
    expect(OrderResource::getNavigationGroup())->toBe('Продажи')->and(OrderResource::getPluralModelLabel())->toBe('Обращения');
});

it('finds requests by number and by phone in global search', function (): void {
    actingAs(staff());
    $order = Order::factory()->create(['number' => 'Z-00077', 'customer_phone' => '+79001234567', 'total' => '1290.00']);
    Order::factory()->create(['customer_phone' => '+79117654321']);

    foreach (['Z-00077', '8 (900) 123-45-67', '900 123'] as $term) {
        $results = OrderResource::getGlobalSearchResults($term);

        expect($results)->toHaveCount(1)
            ->and((string) $results->first()->title)->toBe('Z-00077')
            ->and($results->first()->url)->toBe(OrderResource::getUrl('view', ['record' => $order]))
            ->and(array_values($results->first()->details))->toContain('Заказ', 'Новый');
    }

    expect(array_values(OrderResource::getGlobalSearchResults('Z-00077')->first()->details))->toContain(Money::format(129000));
});
