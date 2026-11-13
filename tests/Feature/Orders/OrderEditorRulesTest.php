<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/order_edit.php';

use App\Enums\OrderChangeKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PriceSource;
use App\Enums\PromoScope;
use App\Events\OrderItemsChanged;
use App\Exceptions\ConcurrentEditException;
use App\Exceptions\EditStockShortageException;
use App\Exceptions\SalesException;
use App\Jobs\SendMailNotification;
use App\Jobs\SendOrderChangedMail;
use App\Mail\OrderItemsChangedCustomerMail;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\Orders\EditData;
use App\Services\Orders\EditItem;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use App\Settings\NotificationSettings;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

// ------------------------------------------------------------------ version (OE-08)

it('rejects the second of two saves made from the same version without losing the first', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $ivanov = User::factory()->admin()->create(['name' => 'Иванов']);
    $petrov = User::factory()->admin()->create(['name' => 'Петров']);
    $version = (int) $order->version;
    $editor = app(OrderEditor::class);

    $editor->apply($order->id, $version, withQty($order, $product, 3), $ivanov);

    try {
        $editor->apply($order->id, $version, withQty($order, $product, 7), $petrov);
        $this->fail('Expected a concurrent edit');
    } catch (ConcurrentEditException $e) {
        expect($e->getMessage())->toContain('Заказ изменён сотрудником Иванов в ')->toContain('Обновите страницу');
    }

    expect($order->refresh()->items->first()->qty)->toBe(3)
        ->and($order->version)->toBe($version + 1)
        ->and($product->fresh()->stock_qty)->toBe(7);
});

it('rejects a save when the status changed after the form was opened', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $openedWith = (int) $order->version;

    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Confirmed, editAdmin());

    expect((int) $order->refresh()->version)->toBe($openedWith + 1);
    expect(fn () => app(OrderEditor::class)->apply($order->id, $openedWith, withQty($order, $product, 5), editAdmin()))
        ->toThrow(ConcurrentEditException::class);
    expect($order->refresh()->items->first()->qty)->toBe(2);
});

it('moves the version on every status change and keeps who did it', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]]);
    $user = editAdmin();

    app(OrderService::class)->changeStatus($order->refresh(), OrderStatus::Confirmed, $user);
    expect((int) $order->refresh()->version)->toBe(2)
        ->and($order->updated_by_user_id)->toBe($user->id);

    app(OrderService::class)->changeStatus($order, OrderStatus::Ready, $user);
    expect((int) $order->refresh()->version)->toBe(3);
});

it('moves the version when a reservation is extended', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]], OrderType::Reservation);

    app(OrderService::class)->extendReservation($order->refresh(), 24, editAdmin());

    expect((int) $order->refresh()->version)->toBe(2);
});

// ------------------------------------------------------------------ editable statuses (OE-01)

it('edits orders and reservations only before they are handed over', function (OrderType $type, OrderStatus $status, bool $editable): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 1]], $type);
    $order->forceFill(['status' => $status])->save();
    $edit = fn () => saveEdit($order, withQty($order, $product, 2));

    if ($editable) {
        $edit();
        expect($order->refresh()->items->first()->qty)->toBe(2)
            ->and(OrderEditor::isEditable($order))->toBeTrue()
            ->and(OrderEditor::blockReason($order))->toBeNull();
    } else {
        expect($edit)->toThrow(SalesException::class)
            ->and(OrderEditor::isEditable($order))->toBeFalse()
            ->and(OrderEditor::blockReason($order))->not->toBeNull()
            ->and($order->refresh()->items->first()->qty)->toBe(1);
    }
})->with([
    'order new' => [OrderType::Order, OrderStatus::New, true],
    'order confirmed' => [OrderType::Order, OrderStatus::Confirmed, true],
    'order ready' => [OrderType::Order, OrderStatus::Ready, true],
    'order shipped' => [OrderType::Order, OrderStatus::Shipped, false],
    'order completed' => [OrderType::Order, OrderStatus::Completed, false],
    'order cancelled' => [OrderType::Order, OrderStatus::Cancelled, false],
    'reservation new' => [OrderType::Reservation, OrderStatus::New, true],
    'reservation reserved' => [OrderType::Reservation, OrderStatus::Reserved, true],
    'reservation completed' => [OrderType::Reservation, OrderStatus::Completed, false],
    'reservation cancelled' => [OrderType::Reservation, OrderStatus::Cancelled, false],
    'reservation expired' => [OrderType::Reservation, OrderStatus::Expired, false],
]);

it('does not edit an inquiry', function (): void {
    $inquiry = Order::factory()->inquiry()->create();
    OrderItem::factory()->forProduct(sellable(), 1)->create(['order_id' => $inquiry->id]);

    expect(fn () => saveEdit($inquiry, editData($inquiry, ['customerName' => 'Другой'])))->toThrow(SalesException::class);
});

// ------------------------------------------------------------------ permissions (OE-11)

it('lets a manager edit the items but not the prices or the discount', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $manager = editManager();
    $item = $order->items->first();

    saveEdit($order, withQty($order, $product, 3), $manager);
    expect($order->refresh()->items->first()->qty)->toBe(3);

    // Direct service calls are refused as well as the form.
    $forbidden = [
        'price' => editData($order, ['items' => [new EditItem($item->id, null, null, 3, 50000)]]),
        'recalculate' => editData($order, ['items' => [new EditItem($item->id, null, null, 3, null, true)]]),
        'discount' => editData($order, ['manualDiscount' => 10000]),
        'new item at a custom price' => addItem(editData($order), sellable(['stock_qty' => 5]), 1, 123),
    ];

    foreach ($forbidden as $name => $data) {
        expect(fn () => saveEdit($order, $data, $manager))->toThrow(AuthorizationException::class, '', $name);
    }

    expect($order->refresh()->items->first()->price)->toBe($item->price)
        ->and($order->manual_discount)->toBe('0.00')
        ->and($order->items)->toHaveCount(1)
        ->and(OrderChange::query()->pluck('batch_uuid')->unique())->toHaveCount(1); // only the first, allowed save
});

it('keeps the stored prices when a manager saves a form that carries them unchanged', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $item = $order->items->first();
    $price = (int) round(((float) $item->price) * 100);

    saveEdit($order, editData($order, ['items' => [new EditItem($item->id, null, null, 4, $price)]]), editManager());

    expect($order->refresh()->items->first()->price)->toBe($item->price);
});

it('lets a manager add an item at its price list price', function (): void {
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]]);
    $new = sellable(['stock_qty' => 10, 'base_price' => '250.00']);

    saveEdit($order, addItem(editData($order), $new, 2), editManager());

    $added = $order->refresh()->items->last();
    expect($added->price)->toBe('250.00')->and($added->total)->toBe('500.00');
});

it('refuses staff without orders.edit_items', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 1]]);

    expect(fn () => saveEdit($order, withQty($order, $product, 2), staffWith(['orders.view', 'orders.update'])))->toThrow(AuthorizationException::class)
        ->and(fn () => saveEdit($order, withQty($order, $product, 2), staffWith(['orders.view', 'orders.edit_items'])))->toThrow(AuthorizationException::class)
        ->and(fn () => app(OrderEditor::class)->preview($order->id, withQty($order, $product, 2), staffWith(['orders.view'])))->toThrow(AuthorizationException::class);
});

it('gives the default manager the right to edit items and not to edit prices', function (): void {
    $manager = editManager();
    $editor = app(OrderEditor::class);

    expect($editor->mayEdit($manager))->toBeTrue()
        ->and($editor->mayEditPrices($manager))->toBeFalse()
        ->and($editor->mayEditPrices(editAdmin()))->toBeTrue();
});

// ------------------------------------------------------------------ prices (OE-03)

it('prices an added item by the price list of the group stored in the order and the new quantity', function (): void {
    $group = CustomerGroup::factory()->create(['name' => 'Опт']);
    $product = gpProduct('1000.00', ['stock_qty' => 50, 'reserved_qty' => 0]);
    gpTier($product, $group, 10, '700.00');
    $order = editableOrder([[sellable(['stock_qty' => 5]), 1]], OrderType::Order, ['customer_group_id' => $group->id, 'customer_group_name' => 'Опт']);

    saveEdit($order, addItem(editData($order), $product, 12));
    $added = $order->refresh()->items->last();

    expect($added->price)->toBe('700.00')
        ->and($added->price_source)->toBe(PriceSource::Tier)
        ->and($added->tier_min_qty)->toBe(10)
        ->and($added->retail_price)->toBe('1000.00');

    // A guest order is priced at retail.
    $guest = editableOrder([[sellable(['stock_qty' => 5]), 1]]);
    saveEdit($guest, addItem(editData($guest), $product, 12));
    expect($guest->refresh()->items->last()->price)->toBe('1000.00');
});

it('keeps the price of an item when its quantity changes and reprices it on request', function (): void {
    $group = CustomerGroup::factory()->create();
    $product = gpProduct('1000.00', ['stock_qty' => 50, 'reserved_qty' => 0]);
    gpTier($product, $group, 10, '700.00');
    $order = editableOrder([[$product, 2]], OrderType::Order, ['customer_group_id' => $group->id]);

    saveEdit($order, withQty($order, $product, 12));
    expect($order->refresh()->items->first()->price)->toBe('1000.00');

    $item = $order->items->first();
    saveEdit($order, editData($order, ['items' => [new EditItem($item->id, null, null, 12, null, true)]]));

    $item->refresh();
    expect($item->price)->toBe('700.00')
        ->and($item->price_source)->toBe(PriceSource::Tier)
        ->and($item->total)->toBe('8400.00');
    $change = OrderChange::query()->where('kind', OrderChangeKind::ItemPrice->value)->first();
    expect($change->old_value)->toBe('1000.00')->and($change->new_value)->toBe('700.00');
});

it('stores a manual price with the source manual and logs it', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $item = $order->items->first();

    saveEdit($order, editData($order, ['items' => [new EditItem($item->id, null, null, 2, 79050)]]), editAdmin());

    $item->refresh();
    expect($item->price)->toBe('790.50')
        ->and($item->price_source)->toBe(PriceSource::Manual)
        ->and($item->total)->toBe('1581.00')
        ->and($order->refresh()->items_total)->toBe('1581.00')
        ->and($order->total)->toBe('1581.00');
});

// ------------------------------------------------------------------ totals (OE-06)

it('computes the totals in kopecks: items, delivery and the manager discount', function (): void {
    $product = sellable(['stock_qty' => 10, 'base_price' => '333.33']);
    $order = editableOrder([[$product, 1]]);

    saveEdit($order, withQty($order, $product, 3)->with(['deliveryMethod' => 'Курьер', 'deliveryPrice' => 30050, 'manualDiscount' => 12345]), editAdmin());

    $order->refresh();
    // 3 × 333.33 = 999.99; + 300.50 − 123.45 = 1177.04
    expect($order->items_total)->toBe('999.99')
        ->and($order->delivery_price)->toBe('300.50')
        ->and($order->manual_discount)->toBe('123.45')
        ->and($order->total)->toBe('1177.04')
        ->and($order->delivery_method)->toBe('Курьер');
});

it('never lets the total go below zero', function (): void {
    $product = sellable(['stock_qty' => 10, 'base_price' => '100.00']);
    $order = editableOrder([[$product, 1]]);

    saveEdit($order, editData($order, ['manualDiscount' => 999900]), editAdmin());

    expect($order->refresh()->total)->toBe('0.00')->and($order->manual_discount)->toBe('9999.00');
});

it('recalculates a percent promo code on the new items (OE-06, PM-18)', function (): void {
    $product = sellable(['stock_qty' => 20, 'base_price' => '1000.00']);
    $other = sellable(['stock_qty' => 20, 'base_price' => '500.00']);
    PromoCode::factory()->percent(10)->create(['code' => 'TEN']);
    $order = promoCheckout(promoCart($product, 2, 'TEN'));
    expect($order->promo_discount)->toBe('200.00')->and($order->total)->toBe('1800.00');

    saveEdit($order, addItem(withQty($order, $product, 5), $other, 2));

    $order->refresh();
    // items 5 000 + 1 000, 10 % = 600
    expect($order->items_total)->toBe('6000.00')
        ->and($order->promo_discount)->toBe('600.00')
        ->and($order->total)->toBe('5400.00')
        ->and($order->items->sum(fn (OrderItem $item): int => (int) round((float) $item->promo_discount * 100)))->toBe(60000)
        ->and(PromoRedemption::query()->where('order_id', $order->id)->value('discount_amount'))->toEqual('600.00');
});

it('checks the scope of the promo code on the new items and ignores the period and the limits', function (): void {
    $inScope = sellable(['stock_qty' => 20, 'base_price' => '1000.00']);
    $outside = sellable(['stock_qty' => 20, 'base_price' => '1000.00']);
    $code = PromoCode::factory()->percent(10)->create(['code' => 'SCOPED', 'scope' => PromoScope::Products, 'usage_limit' => 1]);
    $code->targets()->create(['target_type' => 'product', 'target_id' => $inScope->id]);
    $order = promoCheckout(promoCart($inScope, 1, 'SCOPED'));
    expect($order->promo_discount)->toBe('100.00');

    // The code is exhausted and expired now, the order keeps it (OE-06).
    $code->forceFill(['ends_at' => Carbon::now()->subDay(), 'usage_count' => 1])->save();

    saveEdit($order, addItem(withQty($order, $inScope, 3), $outside, 4));

    $order->refresh();
    expect($order->promo_discount)->toBe('300.00')
        ->and($order->items_total)->toBe('7000.00')
        ->and($order->total)->toBe('6700.00');
});

it('keeps the promo discount when the form is saved without touching the items', function (): void {
    $product = sellable(['stock_qty' => 20, 'base_price' => '1000.00']);
    PromoCode::factory()->percent(10)->create(['code' => 'TEN']);
    $order = promoCheckout(promoCart($product, 2, 'TEN'));

    $result = saveEdit($order, editData($order, ['managerComment' => 'позвонить']));

    expect($result->changed)->toBeTrue()
        ->and($order->refresh()->promo_discount)->toBe('200.00')
        ->and($order->total)->toBe('1800.00');
});

// ------------------------------------------------------------------ change log (OE-07)

it('writes one batch of log rows per save with the user and the old and new values', function (): void {
    $a = sellable(['stock_qty' => 10, 'name' => 'Чайник', 'sku' => 'CH-1']);
    $b = sellable(['stock_qty' => 10, 'name' => 'Кружка', 'sku' => 'KR-1']);
    $order = editableOrder([[$a, 2]]);
    $user = editAdmin();

    saveEdit($order, addItem(withQty($order, $a, 4), $b, 1)->with(['customerName' => 'Пётр Новый', 'deliveryPrice' => 10000]), $user);

    $rows = OrderChange::query()->where('order_id', $order->id)->get();
    expect($rows->pluck('batch_uuid')->unique())->toHaveCount(1)
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$user->id]);

    $byKind = $rows->groupBy(fn (OrderChange $row): string => $row->kind->value);
    $qty = $byKind['item_qty']->first();
    expect($qty->old_value)->toBe('2')->and($qty->new_value)->toBe('4')->and($qty->item_label)->toContain('Чайник')->and($qty->item_label)->toContain('CH-1')
        ->and($byKind['item_added']->first()->item_label)->toContain('Кружка')
        ->and($byKind['item_added']->first()->new_value)->toBe('1 × 1000.00')
        ->and($byKind['delivery']->where('field', 'delivery_price')->first()->new_value)->toBe('100.00')
        ->and($byKind['field']->where('field', 'customer_name')->first()->new_value)->toBe('Пётр Новый')
        ->and($byKind['stock']->pluck('new_value')->sort()->values()->all())->toBe(['-2', '-1']);

    // The next save is another batch.
    saveEdit($order, withQty($order, $a, 1), $user);
    expect(OrderChange::query()->pluck('batch_uuid')->unique())->toHaveCount(2);
});

it('logs a removed item with its snapshot and does not log a save that changed nothing', function (): void {
    $a = sellable(['stock_qty' => 10, 'name' => 'Чайник']);
    $b = sellable(['stock_qty' => 10, 'name' => 'Кружка']);
    $order = editableOrder([[$a, 2], [$b, 1]]);
    $keep = editData($order)->items;

    saveEdit($order, editData($order)->with(['items' => [$keep[1]]]));

    $removed = OrderChange::query()->where('kind', OrderChangeKind::ItemRemoved->value)->first();
    expect($removed->item_label)->toContain('Чайник')
        ->and($removed->order_item_id)->toBe($keep[0]->itemId)
        ->and($removed->old_value)->toStartWith('2 × ');

    $count = OrderChange::query()->count();
    saveEdit($order, editData($order));
    expect(OrderChange::query()->count())->toBe($count);
});

it('does not touch the number, type, date and status history (OE-15) and adds no status row', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 2]]);
    $order->forceFill(['created_at' => '2026-02-02 12:00:00'])->saveQuietly();
    $before = $order->refresh()->only(['number', 'type', 'status', 'created_at']);
    $history = $order->statusHistory()->count();

    saveEdit($order, withQty($order, $product, 3));

    expect($order->refresh()->only(['number', 'type', 'status', 'created_at']))->toEqual($before)
        ->and($order->statusHistory()->count())->toBe($history);
});

// ------------------------------------------------------------------ preview (OE-10)

it('previews the diff, the totals, the stock impact and the warnings without writing anything', function (): void {
    $product = sellable(['stock_qty' => 10, 'base_price' => '1000.00', 'cost_price' => '900.00']);
    $cheap = sellable(['stock_qty' => 10, 'base_price' => '1000.00', 'cost_price' => '1200.00']);
    $order = editableOrder([[$product, 2]]);
    $version = $order->version;

    $plan = app(OrderEditor::class)->preview($order->id, addItem(withQty($order, $product, 1), $cheap, 3)->with(['manualDiscount' => 150000]), editAdmin());

    expect($plan->oldTotal)->toBe(200000)
        ->and($plan->newItemsTotal)->toBe(400000)
        ->and($plan->newTotal)->toBe(250000)
        ->and($plan->totalDelta())->toBe(50000)
        ->and($plan->lines[0]->effect->stockDelta)->toBe(1)
        ->and($plan->lines[1]->effect->stockDelta)->toBe(-3)
        ->and($plan->warnings)->toHaveCount(2)
        ->and($plan->warnings[0])->toContain('ниже закупочной')
        ->and($plan->warnings[1])->toContain('Скидка менеджера больше 30 %')
        ->and(OrderEditor::stockNote($plan->lines[0]->effect, OrderType::Order, 1))->toBe('+1 на склад')
        ->and(OrderEditor::stockNote($plan->lines[1]->effect, OrderType::Order, 3))->toBe('−3 со склада');

    expect($product->fresh()->stock_qty)->toBe(8)
        ->and($order->refresh()->version)->toBe($version)
        ->and(OrderChange::query()->count())->toBe(0)
        ->and($order->items)->toHaveCount(1);
});

it('previews a reservation as holds and releases and a shortage as a warning', function (): void {
    $product = sellable(['stock_qty' => 3]);
    $order = editableOrder([[$product, 2]], OrderType::Reservation);

    $less = app(OrderEditor::class)->preview($order->id, withQty($order, $product, 1), editAdmin());
    $more = app(OrderEditor::class)->preview($order->id, withQty($order, $product, 9), editAdmin());

    expect(OrderEditor::stockNote($less->lines[0]->effect, OrderType::Reservation, 1))->toBe('снять 1 из резерва')
        ->and($more->lines[0]->effect->shortage)->toBe(1)
        ->and($more->warnings[0])->toContain('доступно 1');
});

// ------------------------------------------------------------------ notification (OE-09)

it('queues the customer e-mail only when the checkbox is on and the order has an address', function (bool $notify, ?string $email, bool $sent): void {
    Queue::fake();
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 1]], OrderType::Order, ['customer_email' => $email]);

    saveEdit($order, withQty($order, $product, 2)->with(['notifyCustomer' => $notify]));

    $sent ? Queue::assertPushed(SendOrderChangedMail::class, fn (SendOrderChangedMail $job): bool => $job->orderId === $order->id && $job->oldTotal === 100000 && $job->newTotal === 200000)
        : Queue::assertNotPushed(SendOrderChangedMail::class);
})->with([
    'on' => [true, 'ivan@example.com', true],
    'off' => [false, 'ivan@example.com', false],
    'no e-mail' => [true, null, false],
]);

it('does not notify when nothing changed', function (): void {
    Queue::fake();
    $order = editableOrder([[sellable(['stock_qty' => 10]), 1]], OrderType::Order, ['customer_email' => 'ivan@example.com']);

    saveEdit($order, editData($order, ['notifyCustomer' => true]));

    Queue::assertNotPushed(SendOrderChangedMail::class);
});

it('fires the event after the save with the totals and the batch', function (): void {
    Event::fake([OrderItemsChanged::class]);
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 1]]);

    $result = saveEdit($order, withQty($order, $product, 3)->with(['notifyCustomer' => true]));

    Event::assertDispatched(OrderItemsChanged::class, fn (OrderItemsChanged $event): bool => $event->order->id === $order->id
        && $event->oldTotal === 100000 && $event->newTotal === 300000 && $event->notifyCustomer && $event->batchUuid === $result->batchUuid);
});

it('sends the «Состав заказа изменён» letter with the items and the totals', function (): void {
    Mail::fake();
    $product = sellable(['stock_qty' => 10, 'name' => 'Чайник']);
    $order = editableOrder([[$product, 3]], OrderType::Order, ['customer_email' => 'ivan@example.com', 'number' => 'Z-00042']);

    (new SendOrderChangedMail($order->id, 100000, 300000))->handle();

    Mail::assertSent(OrderItemsChangedCustomerMail::class, function (OrderItemsChangedCustomerMail $mail) use ($order): bool {
        $html = $mail->render();

        return $mail->hasTo('ivan@example.com')
            && $mail->order->is($order)
            && str_contains($html, 'Z-00042')
            && str_contains($html, 'Чайник')
            && str_contains($html, Money::format(300000))
            && str_contains($html, Money::format(100000));
    });
});

it('adds a cabinet notice for an order of an account', function (): void {
    Queue::fake();
    $customer = Customer::factory()->create();
    $product = sellable(['stock_qty' => 10]);
    $order = editableOrder([[$product, 1]], OrderType::Order, ['customer_id' => $customer->id]);

    saveEdit($order, withQty($order, $product, 2)->with(['notifyCustomer' => true]));
    saveEdit($order, withQty($order, $product, 3)->with(['notifyCustomer' => false]));

    $notices = CustomerNotification::query()->where('customer_id', $customer->id)->get();
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->type)->toBe('order_changed')
        ->and($notices->first()->body)->toContain('1 поз.');
});

it('has the notification toggle on and a 30 % discount threshold by default', function (): void {
    $settings = app(NotificationSettings::class);

    expect($settings->notify_customer_order_changed)->toBeTrue()
        ->and($settings->order_discount_warning_percent)->toBe(30);
});

// ------------------------------------------------------------------ inquiry → order (OE-12)

function openInquiry(array $lines, array $attributes = []): Order
{
    $inquiry = Order::factory()->inquiry()->create($attributes + ['customer_email' => 'ivan@example.com']);

    foreach ($lines as [$unit, $qty]) {
        OrderItem::factory()->forProduct($unit, $qty)->create(['order_id' => $inquiry->id, 'price' => null, 'total' => null]);
    }

    return $inquiry->refresh()->load('items');
}

function inquiryForm(Order $inquiry, array $lines, bool $notify = false): EditData
{
    return new EditData(
        array_map(fn (array $line): EditItem => new EditItem(null, $line[0]->id, null, $line[1]), $lines),
        $inquiry->customer_name,
        $inquiry->customer_phone,
        $inquiry->customer_email,
        'Самовывоз',
        null,
        0,
        0,
        null,
        null,
        $notify,
    );
}

it('creates an order from an inquiry: stock, number, link, price list prices, and closes the inquiry', function (): void {
    Queue::fake();
    $product = sellable(['stock_qty' => 10, 'base_price' => '400.00']);
    $inquiry = openInquiry([[$product, 2]], ['customer_comment' => 'нужна цена']);
    $manager = editManager();

    $order = app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, inquiryForm($inquiry, [[$product, 3]], true), $manager);

    expect($order->type)->toBe(OrderType::Order)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->number)->toStartWith('Z-')
        ->and($order->source_order_id)->toBe($inquiry->id)
        ->and($order->stock_applied)->toBeTrue()
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->price)->toBe('400.00')
        ->and($order->items->first()->stock_applied_qty)->toBe(3)
        ->and($order->items_total)->toBe('1200.00')
        ->and($order->total)->toBe('1200.00')
        ->and($order->delivery_method)->toBe('Самовывоз')
        ->and($order->consent_ip)->toBe($inquiry->consent_ip)
        ->and((int) $order->version)->toBe(1)
        ->and($product->fresh()->stock_qty)->toBe(7);

    $inquiry->refresh();
    $history = $inquiry->statusHistory()->latest('id')->first();
    expect($inquiry->status)->toBe(OrderStatus::Closed)
        ->and((int) $inquiry->version)->toBe(2)
        ->and($history->comment)->toBe('Создан заказ '.$order->number)
        ->and($history->user_id)->toBe($manager->id)
        ->and($order->sourceOrder->is($inquiry))->toBeTrue();

    Queue::assertPushed(SendMailNotification::class, fn (SendMailNotification $job): bool => $job->kind === SendMailNotification::CUSTOMER_PLACED && $job->orderId === $order->id);
});

it('takes the group and the account of the inquiry over to the order', function (): void {
    $group = CustomerGroup::factory()->create(['name' => 'Опт']);
    $customer = Customer::factory()->create();
    $product = gpProduct('1000.00', ['stock_qty' => 50, 'reserved_qty' => 0]);
    gpTier($product, $group, 10, '700.00');
    $inquiry = openInquiry([[$product, 10]], ['customer_id' => $customer->id, 'customer_group_id' => $group->id, 'customer_group_name' => 'Опт']);

    $order = app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, inquiryForm($inquiry, [[$product, 10]]), editManager());

    expect($order->customer_id)->toBe($customer->id)
        ->and($order->customer_group_id)->toBe($group->id)
        ->and($order->customer_group_name)->toBe('Опт')
        ->and($order->items->first()->price)->toBe('700.00');
});

it('mails the customer about the new order only by the setting and the checkbox', function (bool $setting, bool $checkbox, bool $sent): void {
    Queue::fake();
    $settings = app(NotificationSettings::class);
    $settings->notify_customer_new = $setting;
    $settings->save();
    $product = sellable(['stock_qty' => 10]);
    $inquiry = openInquiry([[$product, 1]]);

    app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, inquiryForm($inquiry, [[$product, 1]], $checkbox), editManager());

    $sent ? Queue::assertPushed(SendMailNotification::class) : Queue::assertNotPushed(SendMailNotification::class);
})->with([[true, true, true], [false, true, false], [true, false, false]]);

it('creates nothing when the stock is short and keeps the inquiry open', function (): void {
    $product = sellable(['stock_qty' => 1]);
    $inquiry = openInquiry([[$product, 5]]);
    $orders = Order::query()->count();

    expect(fn () => app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, inquiryForm($inquiry, [[$product, 5]]), editManager()))
        ->toThrow(EditStockShortageException::class);

    expect(Order::query()->count())->toBe($orders)
        ->and($inquiry->refresh()->status)->toBe(OrderStatus::New)
        ->and($product->fresh()->stock_qty)->toBe(1);
});

it('creates an order from an inquiry only once, from an open inquiry, with the permission', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $inquiry = openInquiry([[$product, 1]]);
    $form = inquiryForm($inquiry, [[$product, 1]]);

    expect(fn () => app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, $form, staffWith(['orders.view', 'orders.update'])))
        ->toThrow(AuthorizationException::class);

    app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, $form, editManager());

    expect(fn () => app(OrderService::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, $form, editManager()))
        ->toThrow(SalesException::class)
        ->and(Order::query()->where('source_order_id', $inquiry->id)->count())->toBe(1)
        ->and($product->fresh()->stock_qty)->toBe(9);
});

it('refuses an inquiry form opened before the inquiry changed (OE-08)', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $inquiry = openInquiry([[$product, 1]]);
    $opened = (int) $inquiry->version;
    app(OrderService::class)->changeStatus($inquiry, OrderStatus::InProgress, editAdmin());

    expect(fn () => app(OrderService::class)->createFromInquiry($inquiry->refresh(), $opened, inquiryForm($inquiry, [[$product, 1]]), editManager()))
        ->toThrow(ConcurrentEditException::class);
});

it('rejects a soft-deleted variant for a new position (OE-13)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    $s = variantOf($card, [$size->values[0]], ['stock_qty' => 5]);
    $m = variantOf($card, [$size->values[1]], ['stock_qty' => 5]);
    $order = editableOrder([[$s, 1]]);
    $m->delete(); // soft delete keeps is_active

    expect(fn () => saveEdit($order, addItem(editData($order), $m, 2)))->toThrow(SalesException::class)
        ->and(ProductVariant::withTrashed()->find($m->id)->stock_qty)->toBe(5);

    // The variant of an existing line stays editable.
    $s->delete();
    saveEdit($order, withQty($order, $s, 2));
    expect($order->refresh()->items->first()->qty)->toBe(2);
});

it('allocates the number of the order from an inquiry after the stock is locked (OE-12, D-129)', function (): void {
    $product = sellable(['stock_qty' => 10, 'base_price' => '300.00']);
    $inquiry = Order::factory()->inquiry()->create();
    OrderItem::factory()->forProduct($product, 2)->create(['order_id' => $inquiry->id, 'price' => null, 'total' => null]);
    $actor = User::factory()->admin()->create();
    $data = EditData::fromOrder($inquiry->refresh()->load('items'))->with(['items' => [new EditItem(null, $product->id, null, 2, null)]]);

    $order = app(OrderEditor::class)->createFromInquiry($inquiry->refresh(), (int) $inquiry->version, $data, $actor);

    expect($order->number)->toStartWith('Z-')->and($order->number)->not->toContain('tmp');
});
