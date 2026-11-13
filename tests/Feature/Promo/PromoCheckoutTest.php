<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\SalesMode;
use App\Exceptions\CartChangedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\PromoRedemption;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReservationData;
use App\Services\Promo\PromoCustomerHash;
use App\Services\Promo\PromoRedemptionService;
use App\Settings\PromoSettings;
use App\Support\Money;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->product = sellable(['name' => 'Куртка', 'base_price' => '1000.00', 'stock_qty' => 10]);
});

it('creates the order with the promo snapshot and total = items - promo + delivery (PM-18)', function (): void {
    $code = PromoCode::factory()->percent(10)->create(['code' => 'SPRING10']);
    $cart = promoCart($this->product, 3, 'spring10');

    $order = promoCheckout($cart, 'kurerskaia-dostavka');
    $item = $order->items->first();

    // 3 000 - 300 = 2 700, delivery 300 (free from 5 000).
    expect($order->items_total)->toBe('3000.00')
        ->and($order->promo_discount)->toBe('300.00')
        ->and($order->promo_code)->toBe('SPRING10')->and($order->promo_code_id)->toBe($code->id)
        ->and($order->manual_discount)->toBe('0.00')
        ->and($order->delivery_price)->toBe('300.00')
        ->and($order->total)->toBe('3000.00')
        ->and($item->promo_discount)->toBe('300.00')->and($item->total)->toBe('3000.00')
        ->and($code->refresh()->usage_count)->toBe(1)
        ->and(PromoRedemption::query()->where('order_id', $order->id)->first())
        ->discount_amount->toBe('300.00')->released_at->toBeNull();
});

it('splits the fixed discount over the order lines and keeps the sum (PM-08, PM-18)', function (): void {
    $other = sellable(['name' => 'Шапка', 'base_price' => '333.33', 'stock_qty' => 10]);
    PromoCode::factory()->fixed('100.00')->create(['code' => 'MINUS100']);
    $cart = promoCart($this->product, 1, 'MINUS100');
    CartItem::factory()->forProduct($other, 1)->create(['cart_id' => $cart->id]);

    $order = promoCheckout($cart);
    $sum = $order->items->sum(fn ($item): int => Money::fromRubles($item->promo_discount));

    expect($order->promo_discount)->toBe('100.00')->and($sum)->toBe(10_000)
        ->and($order->total)->toBe('1233.33');
});

it('zeroes the delivery price with a free delivery code over the «free from» rule (PM-02, SF-45)', function (): void {
    PromoCode::factory()->freeDelivery()->create(['code' => 'FREESHIP']);

    $order = promoCheckout(promoCart($this->product, 1, 'FREESHIP'), 'kurerskaia-dostavka');

    expect($order->delivery_price)->toBe('0.00')->and($order->total)->toBe('1000.00')
        ->and($order->promo_discount)->toBe('0.00')->and($order->promo_code)->toBe('FREESHIP');
});

it('does not use a promo code when the cart has none (PM-18)', function (): void {
    $order = promoCheckout(promoCart($this->product));

    expect($order->promo_code_id)->toBeNull()->and($order->promo_code)->toBeNull()->and($order->promo_discount)->toBe('0.00')
        ->and($order->total)->toBe('1000.00')->and(PromoRedemption::query()->count())->toBe(0);
});

it('validates the stored code again at checkout and brings the customer back to the cart (PM-11)', function (): void {
    $code = PromoCode::factory()->create(['code' => 'LATE', 'ends_at' => now()->subMinute()]);
    $cart = promoCart($this->product, 1, 'LATE');

    try {
        promoCheckout($cart);
        $this->fail('The expired code must stop the checkout.');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toBe(['Срок действия промокода истёк']);
    }

    expect(Order::query()->count())->toBe(0)->and($cart->refresh()->promo_code)->toBeNull()
        ->and($code->refresh()->usage_count)->toBe(0)
        ->and(Product::query()->find($this->product->id)->stock_qty)->toBe(10);
});

it('refuses with «Промокод исчерпан» when the last use is gone and creates no order (PM-11)', function (): void {
    $code = PromoCode::factory()->oneTime()->create(['code' => 'ONLYONE']);

    $first = promoCheckout(promoCart($this->product, 1, 'ONLYONE'), email: 'a@example.com', phone: '+7 900 111-11-11');
    $cart = promoCart($this->product, 1, 'ONLYONE');

    try {
        promoCheckout($cart, email: 'b@example.com', phone: '+7 900 222-22-22');
        $this->fail('The second order for a one-time code must be refused.');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toBe(['Промокод исчерпан']);
    }

    expect(Order::query()->count())->toBe(1)->and($first->promo_code)->toBe('ONLYONE')
        ->and($code->refresh()->usage_count)->toBe(1)->and(PromoRedemption::query()->count())->toBe(1);
});

it('refuses the atomic UPDATE when the counter reached the limit between the check and the update (PM-11)', function (): void {
    $code = PromoCode::factory()->create(['code' => 'RACE', 'usage_limit' => 1]);
    $lines = [pline(100_000)];

    // The conditional UPDATE is what protects the last use: simulate a counter that moved after the evaluation.
    $service = app(PromoRedemptionService::class);
    expect($service->claim('RACE', $lines, guestPromoViewer())->isApplied())->toBeTrue()
        ->and($code->refresh()->usage_count)->toBe(1)
        ->and($service->claim('RACE', $lines, guestPromoViewer())->message)->toBe('Промокод исчерпан')
        ->and($code->refresh()->usage_count)->toBe(1);
});

it('limits a guest per customer by e-mail and phone at checkout (PM-10)', function (): void {
    PromoCode::factory()->create(['code' => 'ONCE', 'per_customer_limit' => 1]);

    $first = promoCheckout(promoCart($this->product, 1, 'ONCE'), email: 'Ivan@Example.com', phone: '8 900 123-45-67');

    expect($first->promo_code)->toBe('ONCE')
        ->and(PromoRedemption::query()->first()->customer_hash)->toBe(PromoCustomerHash::make('ivan@example.com', '+79001234567'));

    expect(fn () => promoCheckout(promoCart($this->product, 1, 'ONCE'), email: 'ivan@example.com', phone: '+7 900 123 45 67'))
        ->toThrow(CartChangedException::class);

    // Another person is fine.
    expect(promoCheckout(promoCart($this->product, 1, 'ONCE'), email: 'petr@example.com', phone: '+7 900 765-43-21')->promo_code)->toBe('ONCE');
});

it('limits a signed-in customer per customer by id (PM-10)', function (): void {
    PromoCode::factory()->create(['code' => 'ONCE', 'per_customer_limit' => 1]);
    $customer = Customer::factory()->create();
    promoSignIn($this, $customer);

    $first = promoCheckout(promoCart($this->product, 1, 'ONCE'));

    expect($first->customer_id)->toBe($customer->id)
        ->and(PromoRedemption::query()->first()->customer_id)->toBe($customer->id);

    expect(fn () => promoCheckout(promoCart($this->product, 1, 'ONCE')))->toThrow(CartChangedException::class);
});

it('applies «только для зарегистрированных» to a signed-in customer with a verified e-mail at checkout (PM-04)', function (): void {
    PromoCode::factory()->create(['code' => 'MEMBERS', 'only_registered' => true]);

    // A guest is sent back to the cart.
    expect(fn () => promoCheckout(promoCart($this->product, 1, 'MEMBERS')))->toThrow(CartChangedException::class);

    promoSignIn($this, Customer::factory()->create());

    expect(promoCheckout(promoCart($this->product, 1, 'MEMBERS'))->promo_code)->toBe('MEMBERS');
});

it('does not apply a promo code to a reservation or an inquiry, but attributes them to the link (PM-07, PM-24)', function (): void {
    $link = PromoLink::factory()->create();
    $reservation = app(OrderService::class)->createReservation($this->product, new ReservationData(1, 'Иван', '+7 900 123-45-67', null, null, null, true, '127.0.0.1', 'Pest', false, $link->id));
    $inquiry = app(OrderService::class)->createInquiry(sellable(['sales_mode' => SalesMode::Inquiry]), new InquiryData(1, 'Иван', '+7 900 123-45-67', null, null, true, '127.0.0.1', 'Pest', $link->id));

    expect($reservation->promo_code_id)->toBeNull()->and($reservation->promo_discount)->toBe('0.00')->and($reservation->promo_link_id)->toBe($link->id)
        ->and($inquiry->promo_code_id)->toBeNull()->and($inquiry->promo_link_id)->toBe($link->id);
});

it('attributes the order to the link even without a code and ignores an unknown link (PM-24)', function (): void {
    $link = PromoLink::factory()->create();

    expect(promoCheckout(promoCart($this->product), linkId: $link->id)->promo_link_id)->toBe($link->id)
        ->and(promoCheckout(promoCart($this->product), linkId: 99_999)->promo_link_id)->toBeNull();

    $link->delete();

    expect(Order::query()->where('promo_link_id', $link->id)->count())->toBe(0);
});

it('keeps the order untouched when the code or the link is deleted later (PM-18, PM-19)', function (): void {
    $code = PromoCode::factory()->create(['code' => 'KEEP10']);
    $order = promoCheckout(promoCart($this->product, 1, 'KEEP10'));

    // A code with redemptions cannot be deleted (PM-19)...
    expect(fn () => $code->delete())->toThrow(LogicException::class);

    // ... a deactivated one changes no order.
    $code->forceFill(['is_active' => false])->save();
    $order->refresh();

    expect($order->promo_code)->toBe('KEEP10')->and($order->promo_discount)->toBe('100.00')->and($order->total)->toBe('900.00');
});

it('releases the use when the order is cancelled, once (PM-12)', function (): void {
    $code = PromoCode::factory()->oneTime()->create(['code' => 'BACK']);
    $order = promoCheckout(promoCart($this->product, 1, 'BACK'));

    expect($code->refresh()->usage_count)->toBe(1);

    app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled);

    $redemption = PromoRedemption::query()->where('order_id', $order->id)->first();

    expect($code->refresh()->usage_count)->toBe(0)->and($redemption->released_at)->not->toBeNull()
        ->and(Product::query()->find($this->product->id)->stock_qty)->toBe(10);

    // The code can be used again by the same customer...
    $second = promoCheckout(promoCart($this->product, 1, 'BACK'));

    expect($second->promo_code)->toBe('BACK')->and($code->refresh()->usage_count)->toBe(1);

    // ... and a second release of the first order changes nothing.
    expect(app(PromoRedemptionService::class)->release($order))->toBeFalse()
        ->and($code->refresh()->usage_count)->toBe(1);
});

it('keeps the use when «Возвращать код при отмене» is off (PM-12)', function (): void {
    $settings = app(PromoSettings::class);
    $settings->release_on_cancel = false;
    $settings->save();

    $code = PromoCode::factory()->create(['code' => 'KEPT']);
    $order = promoCheckout(promoCart($this->product, 1, 'KEPT'));

    app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled);

    expect($code->refresh()->usage_count)->toBe(1)
        ->and(PromoRedemption::query()->where('order_id', $order->id)->first()->released_at)->toBeNull();
});

it('releases the use of a reservation that expires (PM-12)', function (): void {
    $code = PromoCode::factory()->create(['usage_limit' => 1, 'usage_count' => 1]);
    $reservation = app(OrderService::class)->createReservation($this->product, reservationData());
    PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'order_id' => $reservation->id]);
    $reservation->forceFill(['reserved_until' => now()->subHour()])->save();

    expect(app(OrderService::class)->expireReservations())->toBe(1)
        ->and($code->refresh()->usage_count)->toBe(0);

    // Running the expiry again does not release twice.
    expect(app(OrderService::class)->expireReservations())->toBe(0)->and($code->refresh()->usage_count)->toBe(0);
});

it('gives the use back when an active order is deleted (PM-12)', function (): void {
    $code = PromoCode::factory()->create(['code' => 'GONE']);
    $order = promoCheckout(promoCart($this->product, 1, 'GONE'));

    app(OrderService::class)->delete($order);

    expect($code->refresh()->usage_count)->toBe(0);
});

it('does not count released uses against the per-customer limit afterwards (PM-10, PM-12)', function (): void {
    PromoCode::factory()->create(['code' => 'ONCE', 'per_customer_limit' => 1]);
    $order = promoCheckout(promoCart($this->product, 1, 'ONCE'), email: 'ivan@example.com', phone: '+7 900 123-45-67');
    app(OrderService::class)->changeStatus($order, OrderStatus::Cancelled);

    expect(promoCheckout(promoCart($this->product, 1, 'ONCE'), email: 'ivan@example.com', phone: '+7 900 123-45-67')->promo_code)->toBe('ONCE');
});

it('prices the promo on the group price of the viewer and on the quantity tier (22.1, section 21)', function (): void {
    $group = CustomerGroup::factory()->discount(10)->create(['name' => 'Опт']);
    $code = PromoCode::factory()->percent(10)->forGroups($group)->create(['code' => 'OPT10']);
    app(ViewerContext::class)->actingAs($group);

    $cart = Cart::factory()->create();
    app(CartService::class)->add($cart, $this->product, 2);
    $cart->forceFill(['promo_code' => 'OPT10'])->save();
    $order = promoCheckout($cart);

    // Group price 900 x 2 = 1 800, minus 10 % = 180.
    expect($order->items_total)->toBe('1800.00')->and($order->promo_discount)->toBe('180.00')->and($order->total)->toBe('1620.00')
        ->and($code->refresh()->usage_count)->toBe(1);
});

it('sends the cart back when the only item is on promotion and the code does not apply to such items (PM-05)', function (): void {
    $sale = sellable(['name' => 'Акция', 'base_price' => '1000.00', 'compare_at_price' => '1250.00', 'stock_qty' => 10]);
    PromoCode::factory()->percent(10)->create(['code' => 'PLAIN']);

    try {
        promoCheckout(promoCart($sale, 1, 'PLAIN'));
        $this->fail('No eligible item: the checkout must go back to the cart.');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toBe(['Промокод не подходит к товарам в корзине']);
    }
});

it('discounts an item on promotion by the flags of the code (PM-05, PM-06)', function (): void {
    $sale = sellable(['name' => 'Акция', 'base_price' => '1000.00', 'compare_at_price' => '1250.00', 'stock_qty' => 10]);
    PromoCode::factory()->percent(10)->create(['code' => 'BOTH', 'applies_to_discounted' => true, 'stackable_with_promotions' => true]);
    PromoCode::factory()->percent(30)->create(['code' => 'BEST', 'applies_to_discounted' => true]);

    $both = promoCheckout(promoCart($sale, 1, 'BOTH'));
    $best = promoCheckout(promoCart($sale, 1, 'BEST'));

    // BOTH: 10 % of the promotion price 1 000. BEST: 30 % of the old price 1 250 = 875 < 1 000, discount 125.
    expect($both->promo_discount)->toBe('100.00')->and($best->promo_discount)->toBe('125.00')->and($best->total)->toBe('875.00');
});

it('keeps an item on promotion out of a plain code and still orders when the rest qualifies (PM-05)', function (): void {
    $sale = sellable(['name' => 'Акция', 'base_price' => '1000.00', 'compare_at_price' => '1250.00', 'stock_qty' => 10]);
    PromoCode::factory()->percent(10)->create(['code' => 'PLAIN']);
    $cart = promoCart($this->product, 1, 'PLAIN');
    CartItem::factory()->forProduct($sale, 1)->create(['cart_id' => $cart->id]);

    $order = promoCheckout($cart);
    $discounts = $order->items->mapWithKeys(fn ($item): array => [$item->name => $item->promo_discount])->all();

    expect($order->promo_discount)->toBe('100.00')->and($discounts)->toBe(['Куртка' => '100.00', 'Акция' => '0.00']);
});

it('applies the period of the code on the order moment (PM-04)', function (): void {
    PromoCode::factory()->create(['code' => 'SOON', 'starts_at' => Carbon::now()->addDay()]);

    expect(fn () => promoCheckout(promoCart($this->product, 1, 'SOON')))->toThrow(CartChangedException::class);
});
