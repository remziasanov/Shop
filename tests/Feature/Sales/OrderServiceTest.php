<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Exceptions\CartChangedException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\SalesException;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48]);
    $this->orders = app(OrderService::class);
});

// ---------------------------------------------------------------- checkout

it('creates an order from the cart: snapshot, totals, stock, history, event, cleared cart (SF-46)', function () {
    Event::fake([OrderCreated::class]);
    $a = sellable(['name' => 'Кресло А', 'sku' => 'A-1', 'base_price' => '1000.00', 'stock_qty' => 10, 'unit' => 'шт']);
    $b = sellable(['name' => 'Стол Б', 'sku' => 'B-1', 'base_price' => '250.50', 'stock_qty' => 5]);
    $cart = cartWith($a, $b);
    $cart->items()->where('product_id', $a->id)->update(['qty' => 2]);

    $order = $this->orders->createFromCart($cart, checkoutData('kurerskaia-dostavka'));

    expect($order->type)->toBe(OrderType::Order)
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->number)->toBe('Z-00001')
        ->and($order->customer_phone)->toBe('+79001234567')
        ->and($order->items_total)->toBe('2250.50')
        ->and($order->delivery_price)->toBe('300.00')
        ->and($order->total)->toBe('2550.50')
        ->and($order->delivery_method)->toBe('Курьерская доставка')
        ->and($order->payment_method)->toBe('Наличными при получении')
        ->and($order->stock_applied)->toBeTrue()
        ->and($order->consent_ip)->toBe('127.0.0.1')
        ->and($order->consent_at)->not->toBeNull()
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->first()->only(['sku', 'name', 'unit', 'price', 'qty', 'total', 'stock_applied_qty']))
        ->toBe(['sku' => 'A-1', 'name' => 'Кресло А', 'unit' => 'шт', 'price' => '1000.00', 'qty' => 2, 'total' => '2000.00', 'stock_applied_qty' => 2]);

    expect($a->fresh()->stock_qty)->toBe(8)->and($b->fresh()->stock_qty)->toBe(4)
        ->and($cart->items()->count())->toBe(0)
        ->and($order->statusHistory)->toHaveCount(1)
        ->and($order->statusHistory->first()->from_status)->toBeNull()
        ->and($order->statusHistory->first()->to_status)->toBe(OrderStatus::New)
        ->and($order->statusHistory->first()->user_id)->toBeNull();

    Event::assertDispatched(OrderCreated::class, fn ($e) => $e->order->is($order));
});

it('keeps the snapshot when the product changes later', function () {
    $product = sellable(['name' => 'Старое имя', 'base_price' => '100.00']);
    $order = $this->orders->createFromCart(cartWith($product), checkoutData());

    $product->update(['name' => 'Новое имя', 'base_price' => '999.00']);

    expect($order->fresh()->items->first()->name)->toBe('Старое имя')
        ->and($order->fresh()->items->first()->price)->toBe('100.00');
});

it('makes delivery free from the threshold (SF-45)', function () {
    $product = sellable(['base_price' => '5000.00']);

    $order = $this->orders->createFromCart(cartWith($product), checkoutData('kurerskaia-dostavka'));

    expect($order->delivery_price)->toBe('0.00')->and($order->total)->toBe('5000.00');
});

it('rejects products whose effective mode has no cart (SF-41)', function () {
    $product = sellable(['sales_mode' => SalesMode::Reservation]);
    $cart = cartWith($product);

    try {
        $this->orders->createFromCart($cart, checkoutData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(1)->and($e->reasons[0])->toContain('больше недоступен');
    }

    expect(Order::count())->toBe(0)->and($cart->items()->count())->toBe(0);
});

it('rejects inactive products', function () {
    $product = sellable();
    $cart = cartWith($product);
    $product->update(['is_active' => false]);

    expect(fn () => $this->orders->createFromCart($cart, checkoutData()))->toThrow(CartChangedException::class)
        ->and($cart->items()->count())->toBe(0);
});

it('enforces the minimum order amount (SF-43)', function () {
    sales(['min_order_amount' => 300000]);
    $product = sellable(['base_price' => '1000.00']);
    $cart = cartWith($product);

    expect(fn () => $this->orders->createFromCart($cart, checkoutData()))->toThrow(SalesException::class, 'Минимальная сумма заказа')
        ->and(Order::count())->toBe(0)
        ->and($cart->items()->count())->toBe(1)
        ->and($product->fresh()->stock_qty)->toBe(10);
});

it('stops on a price change, updates the cart and succeeds on the next attempt (PR-07, SF-46)', function () {
    $product = sellable(['base_price' => '1000.00']);
    $cart = cartWith($product);
    $product->update(['base_price' => '1200.00']);

    try {
        $this->orders->createFromCart($cart, checkoutData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons[0])->toContain('изменилась')->toContain(Money::format(100000))->toContain(Money::format(120000));
    }

    expect(Order::count())->toBe(0)
        ->and($cart->items()->first()->price_at_add)->toBe('1200.00')
        ->and($product->fresh()->stock_qty)->toBe(10);

    $order = $this->orders->createFromCart($cart, checkoutData());

    expect($order->items_total)->toBe('1200.00');
});

it('reduces quantity to the stock left and removes sold out items', function () {
    $few = sellable(['stock_qty' => 5]);
    $none = sellable(['stock_qty' => 5]);
    $cart = cartWith($few, $none);
    $cart->items()->where('product_id', $few->id)->update(['qty' => 4]);
    $few->update(['stock_qty' => 2]);
    $none->update(['stock_qty' => 0]);

    try {
        $this->orders->createFromCart($cart, checkoutData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(2);
    }

    expect($cart->items()->count())->toBe(1)->and($cart->items()->first()->qty)->toBe(2);
});

it('allows ordering beyond stock with backorder and deducts only what exists', function () {
    sales(['allow_backorder' => true]);
    $product = sellable(['stock_qty' => 2]);
    $cart = cartWith($product);
    $cart->items()->update(['qty' => 5]);

    $order = $this->orders->createFromCart($cart, checkoutData());

    expect($order->items->first()->qty)->toBe(5)
        ->and($order->items->first()->stock_applied_qty)->toBe(2)
        ->and($product->fresh()->stock_qty)->toBe(0);

    $this->orders->changeStatus($order, OrderStatus::Cancelled);

    expect($product->fresh()->stock_qty)->toBe(2);
});

it('requires consent, a valid phone, a name, an address when needed and valid methods', function () {
    $cart = cartWith(sellable());

    expect(fn () => $this->orders->createFromCart($cart, checkoutData(consent: false)))->toThrow(SalesException::class, 'согласие')
        ->and(fn () => $this->orders->createFromCart($cart, new CheckoutData('Иван', '123', null, 'samovyvoz', null, 'nalicnymi-pri-polucenii', null, true, '127.0.0.1')))
        ->toThrow(SalesException::class, 'номер телефона')
        ->and(fn () => $this->orders->createFromCart($cart, new CheckoutData(' ', '9001234567', null, 'samovyvoz', null, 'nalicnymi-pri-polucenii', null, true, '127.0.0.1')))
        ->toThrow(SalesException::class, 'имя')
        ->and(fn () => $this->orders->createFromCart($cart, new CheckoutData('Иван', '9001234567', null, 'kurerskaia-dostavka', ' ', 'nalicnymi-pri-polucenii', null, true, '127.0.0.1')))
        ->toThrow(SalesException::class, 'адрес')
        ->and(fn () => $this->orders->createFromCart($cart, new CheckoutData('Иван', '9001234567', null, 'nope', null, 'nalicnymi-pri-polucenii', null, true, '127.0.0.1')))
        ->toThrow(SalesException::class)
        ->and(Order::count())->toBe(0);
});

it('rejects an empty cart', function () {
    expect(fn () => $this->orders->createFromCart(cartWith(), checkoutData()))->toThrow(SalesException::class, 'Корзина пуста');
});

// ---------------------------------------------------------------- reservation

it('creates a reservation with a term and holds stock (SF-50, SF-51)', function () {
    Event::fake([OrderCreated::class]);
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
    $product = sellable(['stock_qty' => 5]);

    $order = $this->orders->createReservation($product, reservationData(2, Carbon::parse('2026-11-02')));

    expect($order->type)->toBe(OrderType::Reservation)
        ->and($order->number)->toBe('R-00001')
        ->and($order->status)->toBe(OrderStatus::New)
        ->and($order->reserved_until->toDateTimeString())->toBe('2026-11-03 10:00:00')
        ->and($order->desired_date->toDateString())->toBe('2026-11-02')
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->stock_applied_qty)->toBe(2)
        ->and($order->stock_applied)->toBeTrue()
        ->and($order->total)->toBe('2000.00');

    expect($product->fresh()->reserved_qty)->toBe(2)->and($product->fresh()->stock_qty)->toBe(5);
    Event::assertDispatched(OrderCreated::class);
});

it('rejects a reservation beyond available stock, in the wrong mode or with a bad date', function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
    $product = sellable(['stock_qty' => 2]);

    expect(fn () => $this->orders->createReservation($product, reservationData(3)))->toThrow(SalesException::class, 'не более 2')
        ->and(fn () => $this->orders->createReservation(sellable(['sales_mode' => SalesMode::Cart]), reservationData()))->toThrow(SalesException::class, 'нельзя зарезервировать')
        ->and(fn () => $this->orders->createReservation($product, reservationData(1, Carbon::parse('2026-11-04'))))->toThrow(SalesException::class, 'Желаемая дата')
        ->and(fn () => $this->orders->createReservation($product, reservationData(1, Carbon::parse('2026-10-30'))))->toThrow(SalesException::class, 'Желаемая дата')
        ->and(fn () => $this->orders->createReservation($product, reservationData(consent: false)))->toThrow(SalesException::class, 'согласие')
        ->and($product->fresh()->reserved_qty)->toBe(0);
});

it('does not let two reservations take the last unit', function () {
    $product = sellable(['stock_qty' => 1]);

    $this->orders->createReservation($product, reservationData(1));

    expect(fn () => $this->orders->createReservation($product, reservationData(1)))->toThrow(SalesException::class);
});

it('extends a reservation from the later of now and the term (OR-05)', function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
    $user = User::factory()->create();
    $order = $this->orders->createReservation(sellable(), reservationData());

    $this->orders->extendReservation($order, 24, $user);
    expect($order->reserved_until->toDateTimeString())->toBe('2026-11-04 10:00:00');

    $this->travelTo(Carbon::parse('2026-11-10 10:00:00'));
    $this->orders->extendReservation($order, 5);
    expect($order->reserved_until->toDateTimeString())->toBe('2026-11-10 15:00:00');

    $history = $order->statusHistory()->reorder('id', 'desc')->first();
    expect($history->comment)->toBe('Продлён на 5 ч')->and($history->to_status)->toBe(OrderStatus::New);

    expect(fn () => $this->orders->extendReservation($order, 0))->toThrow(SalesException::class);
});

it('extends only active reservations', function () {
    $order = Order::factory()->reservation()->status(OrderStatus::Cancelled)->create();
    $inquiry = Order::factory()->inquiry()->create();

    expect(fn () => $this->orders->extendReservation($order, 5))->toThrow(SalesException::class)
        ->and(fn () => $this->orders->extendReservation($inquiry, 5))->toThrow(SalesException::class);
});

it('expires overdue reservations and returns the reserved stock, idempotently (SF-53)', function () {
    Event::fake([OrderStatusChanged::class]);
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
    $product = sellable(['stock_qty' => 5]);
    $overdue = $this->orders->createReservation($product, reservationData(2));
    $confirmed = $this->orders->createReservation($product, reservationData(1));
    $this->orders->changeStatus($confirmed, OrderStatus::Reserved);
    $fresh = $this->orders->createReservation($product, reservationData(1));
    $this->orders->extendReservation($fresh, 100);

    $this->travelTo(Carbon::parse('2026-11-04 10:00:00'));

    expect($this->orders->expireReservations())->toBe(2)
        ->and($this->orders->expireReservations())->toBe(0);

    expect($overdue->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($confirmed->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($fresh->fresh()->status)->toBe(OrderStatus::New)
        ->and($product->fresh()->reserved_qty)->toBe(1)
        ->and($overdue->statusHistory()->reorder('id', 'desc')->first()->user_id)->toBeNull()
        ->and($overdue->statusHistory()->reorder('id', 'desc')->first()->to_status)->toBe(OrderStatus::Expired);

    Event::assertDispatched(OrderStatusChanged::class, fn ($e) => $e->to === OrderStatus::Expired && $e->user === null);
});

// ---------------------------------------------------------------- inquiry

it('creates an inquiry with the current price and no stock change (SF-60)', function () {
    $product = sellable(['sales_mode' => SalesMode::Inquiry, 'base_price' => '700.00', 'stock_qty' => 3]);

    $order = $this->orders->createInquiry($product, inquiryData(2));

    expect($order->type)->toBe(OrderType::Inquiry)
        ->and($order->number)->toBe('Q-00001')
        ->and($order->items->first()->price)->toBe('700.00')
        ->and($order->items->first()->qty)->toBe(2)
        ->and($order->stock_applied)->toBeFalse()
        ->and($product->fresh()->stock_qty)->toBe(3)
        ->and($product->fresh()->reserved_qty)->toBe(0);
});

it('leaves the price empty for «price on request»', function () {
    $product = sellable(['price_on_request' => true]);

    $order = $this->orders->createInquiry($product, inquiryData());

    expect($order->items->first()->price)->toBeNull()->and($order->items->first()->total)->toBeNull()
        ->and($order->total)->toBeNull();
});

it('does not accept an inquiry for a showcase product', function () {
    expect(fn () => $this->orders->createInquiry(sellable(['sales_mode' => SalesMode::Showcase]), inquiryData()))->toThrow(SalesException::class);
});

it('accepts an inquiry for an out of stock product', function () {
    $order = $this->orders->createInquiry(sellable(['stock_qty' => 0, 'sales_mode' => SalesMode::Cart]), inquiryData());

    expect($order->type)->toBe(OrderType::Inquiry);
});

// ---------------------------------------------------------------- status changes

it('walks an order through statuses writing history and firing events (OR-04)', function () {
    Event::fake([OrderStatusChanged::class]);
    $manager = User::factory()->create();
    $order = $this->orders->createFromCart(cartWith(sellable()), checkoutData());

    $this->orders->changeStatus($order, OrderStatus::Confirmed, $manager, 'Позвонили');
    $this->orders->changeStatus($order, OrderStatus::Shipped, $manager);
    $this->orders->changeStatus($order, OrderStatus::Completed, $manager);

    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($order->statusHistory)->toHaveCount(4);
    $row = $order->statusHistory()->where('to_status', 'confirmed')->first();
    expect($row->comment)->toBe('Позвонили')->and($row->user_id)->toBe($manager->id)->and($row->from_status)->toBe(OrderStatus::New);

    Event::assertDispatchedTimes(OrderStatusChanged::class, 3);
    expect(fn () => $this->orders->changeStatus($order, OrderStatus::Cancelled))->toThrow(InvalidOrderTransitionException::class);
});

it('rejects forbidden transitions and manual expiry', function () {
    $order = Order::factory()->create();
    $reservation = Order::factory()->reservation()->create();

    expect(fn () => $this->orders->changeStatus($order, OrderStatus::Completed))->toThrow(InvalidOrderTransitionException::class)
        ->and(fn () => $this->orders->changeStatus($reservation, OrderStatus::Expired))->toThrow(InvalidOrderTransitionException::class)
        ->and($order->fresh()->status)->toBe(OrderStatus::New);
});

it('returns stock when an order is cancelled, exactly once', function () {
    $product = sellable(['stock_qty' => 10]);
    $order = $this->orders->createFromCart(cartWith($product), checkoutData());
    expect($product->fresh()->stock_qty)->toBe(9);

    $this->orders->changeStatus($order, OrderStatus::Cancelled);

    expect($product->fresh()->stock_qty)->toBe(10)->and($order->fresh()->stock_applied)->toBeFalse();
    expect(fn () => $this->orders->changeStatus($order, OrderStatus::Cancelled))->toThrow(InvalidOrderTransitionException::class);
    expect($product->fresh()->stock_qty)->toBe(10);
});

it('completes and cancels reservations with the right stock effects', function () {
    $product = sellable(['stock_qty' => 5]);
    $bought = $this->orders->createReservation($product, reservationData(2));
    $cancelled = $this->orders->createReservation($product, reservationData(1));

    $this->orders->changeStatus($bought, OrderStatus::Completed);
    expect($product->fresh()->reserved_qty)->toBe(1)->and($product->fresh()->stock_qty)->toBe(3);

    $this->orders->changeStatus($cancelled, OrderStatus::Cancelled);
    expect($product->fresh()->reserved_qty)->toBe(0)->and($product->fresh()->stock_qty)->toBe(3);
});

it('can set a new term when confirming a reservation', function () {
    $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
    $order = $this->orders->createReservation(sellable(), reservationData());

    $this->orders->changeStatus($order, OrderStatus::Reserved, null, null, Carbon::parse('2026-11-20 12:00:00'));

    expect($order->reserved_until->toDateTimeString())->toBe('2026-11-20 12:00:00');
    expect(fn () => $this->orders->changeStatus($order, OrderStatus::Completed, null, null, Carbon::parse('2026-11-30')))->not->toThrow(Throwable::class);
});

it('moves inquiries without touching stock', function () {
    $order = $this->orders->createInquiry(sellable(['sales_mode' => SalesMode::Inquiry]), inquiryData());

    $this->orders->changeStatus($order, OrderStatus::InProgress);
    $this->orders->changeStatus($order, OrderStatus::Closed);

    expect($order->status)->toBe(OrderStatus::Closed);
});

it('provides scopes for type, new and expiring reservations', function () {
    Order::factory()->create();
    Order::factory()->inquiry()->create();
    Order::factory()->reservation()->reservedUntil(now()->addHours(5))->create();
    Order::factory()->reservation()->reservedUntil(now()->addHours(30))->create();
    Order::factory()->reservation()->status(OrderStatus::Cancelled)->reservedUntil(now()->addHours(2))->create();

    expect(Order::ofType(OrderType::Inquiry)->count())->toBe(1)
        ->and(Order::query()->new()->count())->toBe(4)
        ->and(Order::expiringWithin(24)->count())->toBe(1)
        ->and(Order::expiringWithin(48)->count())->toBe(2);
});
