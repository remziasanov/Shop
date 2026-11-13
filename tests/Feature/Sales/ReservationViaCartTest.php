<?php

declare(strict_types=1);

use App\Enums\CartLineKind;
use App\Enums\CheckoutChoice;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Exceptions\CartChangedException;
use App\Exceptions\SalesException;
use App\Filament\Pages\Settings\ManageSalesSettings;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Jobs\SendOrderNotifications;
use App\Livewire\Storefront\AddToCart;
use App\Livewire\Storefront\CartPage;
use App\Livewire\Storefront\CheckoutForm;
use App\Mail\OrderPlacedCustomerMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use App\Services\Notifications\Messages\NewRequestMessage;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderQuery;
use App\Services\Orders\OrderService;
use App\Services\Sales\CartAvailability;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use App\Support\QtyLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Reservation of several products through the cart (MR-01…MR-13, D-083).
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48, 'reservation_via_cart' => true, 'reservation_max_items' => 20]);
});

// ------------------------------------------------------------------ settings and the cart (MR-01, MR-02, MR-03)

it('has the reservation settings with their defaults (MR-03, MR-13)', function (): void {
    $settings = app(SalesSettings::class);

    expect($settings->reservation_via_cart)->toBeTrue()
        ->and($settings->reservation_max_items)->toBe(20);
});

it('accepts reservation and both products in the cart but never inquiry or showcase (MR-01)', function (): void {
    $carts = app(CartService::class);
    $cart = Cart::factory()->create();

    $carts->add($cart, mrReservable());
    $carts->add($cart, sellable(['sales_mode' => SalesMode::Both]));
    $carts->add($cart, sellable(['sales_mode' => SalesMode::Cart]));

    expect($cart->items()->count())->toBe(3);

    foreach ([SalesMode::Inquiry, SalesMode::Showcase] as $mode) {
        expect(fn () => $carts->add($cart, sellable(['sales_mode' => $mode])))->toThrow(SalesException::class);
    }

    expect($cart->items()->count())->toBe(3);
});

it('resolves the kind of every row on every load (MR-02)', function (): void {
    $cartProduct = sellable(['sales_mode' => SalesMode::Cart]);
    $reservation = mrReservable();
    $both = sellable(['sales_mode' => SalesMode::Both]);
    $cart = mrCart([[$cartProduct], [$reservation], [$both]]);

    $kinds = collect(app(CartService::class)->summary($cart)->lines)->mapWithKeys(fn ($line) => [$line->item->product_id => $line->kind]);

    expect($kinds[$cartProduct->id])->toBe(CartLineKind::Order)
        ->and($kinds[$reservation->id])->toBe(CartLineKind::Reservation)
        ->and($kinds[$both->id])->toBe(CartLineKind::Flexible);

    // The admin changes the modes: the next load follows (no kind is stored).
    $reservation->update(['sales_mode' => SalesMode::Cart]);
    $both->update(['sales_mode' => SalesMode::Reservation]);

    $kinds = collect(app(CartService::class)->summary($cart)->lines)->mapWithKeys(fn ($line) => [$line->item->product_id => $line->kind]);

    expect($kinds[$reservation->id])->toBe(CartLineKind::Order)
        ->and($kinds[$both->id])->toBe(CartLineKind::Reservation);
});

it('does not put a back-ordered product without stock into a reservation (MR-02)', function (): void {
    sales(['allow_backorder' => true]);
    $carts = app(CartService::class);
    $cart = Cart::factory()->create();

    // «Корзина и резерв» without stock can only be ordered; «Резерв» without stock cannot be added at all.
    $carts->add($cart, sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 0]));
    expect(fn () => $carts->add($cart, mrReservable(['stock_qty' => 0])))->toThrow(SalesException::class);

    expect(app(CartService::class)->summary($cart)->lines[0]->kind)->toBe(CartLineKind::Order);
});

it('limits a reservation row to the available stock (MR-07)', function (): void {
    $product = mrReservable(['stock_qty' => 4, 'reserved_qty' => 1]);
    $cart = Cart::factory()->create();

    $item = app(CartService::class)->add($cart, $product, 10);

    expect($item->qty)->toBe(3);
});

it('behaves as version 1 with the setting off (MR-03)', function (): void {
    sales(['reservation_via_cart' => false]);
    $carts = app(CartService::class);
    $cart = Cart::factory()->create();

    expect(fn () => $carts->add($cart, mrReservable()))->toThrow(SalesException::class);

    // A «Корзина и резерв» product is a plain order row; the choice of the form changes nothing.
    $both = sellable(['sales_mode' => SalesMode::Both]);
    $carts->add($cart, $both, 2);

    expect($carts->summary($cart)->lines[0]->kind)->toBe(CartLineKind::Order);

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData(CheckoutChoice::Reservation));

    expect($result->order)->not->toBeNull()
        ->and($result->reservation)->toBeNull()
        ->and($both->fresh()->stock_qty)->toBe(8)
        ->and($both->fresh()->reserved_qty)->toBe(0);
});

it('drops a reservation row from the cart at checkout when the setting was switched off meanwhile', function (): void {
    $product = mrReservable();
    $cart = mrCart([[$product]]);
    sales(['reservation_via_cart' => false]);

    try {
        app(OrderService::class)->checkoutFromCart($cart, mrReserveData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(1);
    }

    expect(Order::count())->toBe(0)->and($cart->items()->count())->toBe(0);
});

// ------------------------------------------------------------------ reservation of several products (MR-06, MR-07, MR-09)

it('reserves three products with one term and holds every row (MR-05, MR-06, MR-07)', function (): void {
    $a = mrReservable(['stock_qty' => 5]);
    $b = mrReservable(['stock_qty' => 3]);
    $c = mrReservable(['stock_qty' => 2]);
    $cart = mrCart([[$a, 2], [$b, 3], [$c, 1]]);
    $desired = now()->addDay();

    $result = app(OrderService::class)->checkoutFromCart($cart, mrReserveData($desired));

    $reservation = $result->reservation;
    expect($result->order)->toBeNull()
        ->and($reservation)->not->toBeNull()
        ->and($reservation->type)->toBe(OrderType::Reservation)
        ->and($reservation->status)->toBe(OrderStatus::New)
        ->and($reservation->items)->toHaveCount(3)
        ->and($reservation->reserved_via_cart)->toBeTrue()
        ->and($reservation->related_order_id)->toBeNull()
        ->and($reservation->stock_applied)->toBeTrue()
        ->and($reservation->delivery_method)->toBeNull()
        ->and($reservation->payment_method)->toBeNull()
        ->and($reservation->desired_date->toDateString())->toBe($desired->toDateString())
        ->and(abs($reservation->reserved_until->diffInSeconds(now()->addHours(48))))->toBeLessThan(5)
        ->and($reservation->total)->toBe('6000.00')
        ->and($a->fresh()->reserved_qty)->toBe(2)
        ->and($b->fresh()->reserved_qty)->toBe(3)
        ->and($c->fresh()->reserved_qty)->toBe(1)
        // The stock itself is not touched by a reservation.
        ->and($a->fresh()->stock_qty)->toBe(5)
        ->and($cart->items()->count())->toBe(0);
});

it('returns the primary request for the old createFromCart callers', function (): void {
    $cart = mrCart([[mrReservable()]]);

    $order = app(OrderService::class)->createFromCart($cart, mrReserveData());

    expect($order->type)->toBe(OrderType::Reservation);
});

it('refuses the whole reservation when one row is short of stock and lets the customer reduce it (MR-07)', function (): void {
    $a = mrReservable(['stock_qty' => 5]);
    $b = mrReservable(['stock_qty' => 5]);
    $cart = mrCart([[$a, 2], [$b, 4]]);
    // Another buyer takes 3 of the 5 of B meanwhile.
    $b->forceFill(['reserved_qty' => 3])->save();

    try {
        app(OrderService::class)->checkoutFromCart($cart, mrReserveData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(1)->and($e->reasons[0])->toContain('доступно 2 из 4')
            ->and($e->shortages)->toBe([CartItem::query()->where('product_id', $b->id)->value('id')]);
    }

    // All or nothing: no request, no hold, the cart rows stay as they were.
    expect(Order::count())->toBe(0)
        ->and($a->fresh()->reserved_qty)->toBe(0)
        ->and($b->fresh()->reserved_qty)->toBe(3)
        ->and($cart->items()->count())->toBe(2)
        ->and((int) CartItem::query()->where('product_id', $b->id)->value('qty'))->toBe(4);

    // «Уменьшить до доступного» on the cart page.
    session()->put('cart_shortages', [(int) CartItem::query()->where('product_id', $b->id)->value('id')]);

    livewireWithCart(CartPage::class, $cart)
        ->assertSeeHtml('data-reserve-shortage')
        ->call('reduceShortages')
        ->assertDontSeeHtml('data-reserve-shortage');

    expect((int) CartItem::query()->where('product_id', $b->id)->value('qty'))->toBe(2);

    $result = app(OrderService::class)->checkoutFromCart($cart->refresh(), mrReserveData());

    expect($result->reservation->items)->toHaveCount(2)->and($b->fresh()->reserved_qty)->toBe(5);
});

it('rolls back the order part when the reservation part fails (one transaction)', function (): void {
    $order = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 5]);
    $reservation = mrReservable(['stock_qty' => 2]);
    $cart = mrCart([[$order, 2], [$reservation, 2]]);
    $reservation->forceFill(['reserved_qty' => 1])->save();

    expect(fn () => app(OrderService::class)->checkoutFromCart($cart, mrMixedData()))->toThrow(CartChangedException::class);

    expect(Order::count())->toBe(0)->and($order->fresh()->stock_qty)->toBe(5);
});

it('returns the stock of every row when the reservation expires, with one notification (MR-09)', function (): void {
    $a = mrReservable(['stock_qty' => 5]);
    $b = mrReservable(['stock_qty' => 5]);
    $reservation = app(OrderService::class)->checkoutFromCart(mrCart([[$a, 2], [$b, 3]]), mrReserveData())->reservation;

    Queue::fake();
    Carbon::setTestNow(now()->addHours(49));

    expect(app(OrderService::class)->expireReservations())->toBe(1);

    $reservation->refresh();
    expect($reservation->status)->toBe(OrderStatus::Expired)
        ->and($a->fresh()->reserved_qty)->toBe(0)
        ->and($b->fresh()->reserved_qty)->toBe(0)
        ->and($reservation->items->every(fn ($item) => $item->stock_applied_qty === 0))->toBeTrue();
    Queue::assertPushed(SendOrderNotifications::class, 1);
    Queue::assertPushed(SendOrderNotifications::class, fn (SendOrderNotifications $job): bool => $job->event === 'expired' && $job->orderId === $reservation->id);

    Carbon::setTestNow();
});

it('extends the term of the whole reservation at once (MR-09)', function (): void {
    $reservation = app(OrderService::class)->checkoutFromCart(mrCart([[mrReservable()], [mrReservable()]]), mrReserveData())->reservation;
    $until = $reservation->reserved_until;

    app(OrderService::class)->extendReservation($reservation, 24);

    expect($reservation->fresh()->reserved_until->equalTo($until->copy()->addHours(24)))->toBeTrue()
        ->and($reservation->items)->toHaveCount(2);
});

it('reserves variants of cards and holds their own stock (MR-07, VR-14)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard(['sales_mode' => SalesMode::Reservation], $size);
    $s = variantOf($card, [$size->values[0]], ['stock_qty' => 3]);
    $m = variantOf($card, [$size->values[1]], ['stock_qty' => 1]);
    $plain = mrReservable(['stock_qty' => 4]);

    $cart = Cart::factory()->create();
    CartItem::factory()->forVariant($s, 2)->create(['cart_id' => $cart->id]);
    CartItem::factory()->forVariant($m, 1)->create(['cart_id' => $cart->id]);
    CartItem::factory()->forProduct($plain, 1)->create(['cart_id' => $cart->id]);

    $reservation = app(OrderService::class)->checkoutFromCart($cart, mrReserveData())->reservation;

    expect($reservation->items)->toHaveCount(3)
        ->and($s->fresh()->reserved_qty)->toBe(2)
        ->and($m->fresh()->reserved_qty)->toBe(1)
        ->and($plain->fresh()->reserved_qty)->toBe(1);

    // Nothing is left for M: the next reservation of it is refused as a whole, S stays untouched.
    $second = Cart::factory()->create();
    CartItem::factory()->forVariant($s, 1)->create(['cart_id' => $second->id]);
    CartItem::factory()->forVariant($m, 1)->create(['cart_id' => $second->id]);

    expect(fn () => app(OrderService::class)->checkoutFromCart($second, mrReserveData()))->toThrow(CartChangedException::class)
        ->and($s->fresh()->reserved_qty)->toBe(2);
});

// ------------------------------------------------------------------ mixed cart (MR-04, MR-05, MR-08)

it('creates an order and a reservation linked to each other from a mixed cart (MR-05)', function (): void {
    $buy = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 5, 'base_price' => '1000.00']);
    $hold = mrReservable(['stock_qty' => 5, 'base_price' => '2000.00']);
    $cart = mrCart([[$buy, 2], [$hold, 1]]);

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData());

    expect($result->all())->toHaveCount(2)
        ->and($result->order->type)->toBe(OrderType::Order)
        ->and($result->reservation->type)->toBe(OrderType::Reservation)
        ->and($result->order->items)->toHaveCount(1)
        ->and($result->reservation->items)->toHaveCount(1)
        ->and($result->order->fresh()->related_order_id)->toBe($result->reservation->id)
        ->and($result->reservation->fresh()->related_order_id)->toBe($result->order->id)
        ->and($result->order->total)->toBe('2000.00')
        ->and($result->order->delivery_method)->not->toBeNull()
        ->and($result->reservation->total)->toBe('2000.00')
        ->and($result->reservation->delivery_method)->toBeNull()
        // The order deducts the stock, the reservation holds it.
        ->and($buy->fresh()->stock_qty)->toBe(3)
        ->and($buy->fresh()->reserved_qty)->toBe(0)
        ->and($hold->fresh()->stock_qty)->toBe(5)
        ->and($hold->fresh()->reserved_qty)->toBe(1)
        ->and($cart->items()->count())->toBe(0)
        ->and($result->primary()->id)->toBe($result->order->id);
});

it('applies the minimum order amount to the order part only (MR-08)', function (): void {
    sales(['min_order_amount' => 300000]);
    $buy = sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '1000.00']);
    $hold = mrReservable(['base_price' => '100.00']);

    // The order part (1000) is below the minimum (3000): refused, whatever the reservation is worth.
    expect(fn () => app(OrderService::class)->checkoutFromCart(mrCart([[$buy], [mrReservable(['base_price' => '9000.00'])]]), mrMixedData()))
        ->toThrow(SalesException::class, 'Минимальная сумма заказа');

    // A reservation has no minimum at all.
    $result = app(OrderService::class)->checkoutFromCart(mrCart([[$hold]]), mrReserveData());

    expect($result->reservation)->not->toBeNull()->and($result->reservation->total)->toBe('100.00');

    // The cart page: a reservation-only cart is not blocked by the minimum.
    $summary = app(CartService::class)->summary(mrCart([[mrReservable(['base_price' => '100.00'])]]));

    expect($summary->minOrderMissing)->toBe(0)->and($summary->canCheckout)->toBeTrue()->and($summary->hasReservation)->toBeTrue()->and($summary->hasOrder)->toBeFalse();
});

it('applies the promo code to the order part only (MR-08, PM-07)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'SALE10']);
    $buy = sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '1000.00']);
    $hold = mrReservable(['base_price' => '1000.00']);
    $cart = mrCart([[$buy], [$hold]]);
    $cart->forceFill(['promo_code' => 'SALE10'])->save();

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData());

    expect($result->order->promo_discount)->toBe('100.00')
        ->and($result->order->total)->toBe('900.00')
        ->and($result->reservation->promo_discount)->toBe('0.00')
        ->and($result->reservation->promo_code)->toBeNull()
        ->and($result->reservation->total)->toBe('1000.00');
});

it('evaluates the promo code of the cart page on the order rows only (MR-08)', function (): void {
    PromoCode::factory()->percent(10)->create(['code' => 'SALE10']);
    $cart = mrCart([[sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '1000.00'])], [mrReservable(['base_price' => '1000.00'])]]);
    $cart->forceFill(['promo_code' => 'SALE10'])->save();

    $component = livewireWithCart(CartPage::class, $cart);

    $component->assertSeeHtml('data-reservation-total')->assertSeeHtml('data-cart-block="reservation"')->assertSeeHtml('data-cart-block="order"');
    expect($component->html())->toContain('data-promo-discount')->and($component->html())->toContain('100');
});

it('sends the flexible rows where the customer chose (MR-04)', function (CheckoutChoice $choice): void {
    $both = sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 5]);
    $buy = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 5]);
    $hold = mrReservable(['stock_qty' => 5]);
    $cart = mrCart([[$both, 2], [$buy, 1], [$hold, 1]]);

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData($choice));

    $orderIds = $result->order->items->pluck('product_id')->all();
    $reservationIds = $result->reservation->items->pluck('product_id')->all();

    expect($orderIds)->toContain($buy->id)->and($reservationIds)->toContain($hold->id);

    if ($choice === CheckoutChoice::Order) {
        expect($orderIds)->toContain($both->id)->and($both->fresh()->stock_qty)->toBe(3)->and($both->fresh()->reserved_qty)->toBe(0);
    } else {
        expect($reservationIds)->toContain($both->id)->and($both->fresh()->stock_qty)->toBe(5)->and($both->fresh()->reserved_qty)->toBe(2);
    }
})->with([CheckoutChoice::Order, CheckoutChoice::Reservation]);

it('follows the choice for a cart with only flexible rows (one request)', function (): void {
    $both = sellable(['sales_mode' => SalesMode::Both]);

    $reserved = app(OrderService::class)->checkoutFromCart(mrCart([[$both]]), mrReserveData(choice: CheckoutChoice::Reservation));
    $ordered = app(OrderService::class)->checkoutFromCart(mrCart([[$both]]), mrMixedData(CheckoutChoice::Order));

    expect($reserved->order)->toBeNull()->and($reserved->reservation)->not->toBeNull()
        ->and($ordered->reservation)->toBeNull()->and($ordered->order)->not->toBeNull();
});

it('decides the kind again at checkout when the product mode changed after adding (MR-02)', function (): void {
    $both = sellable(['sales_mode' => SalesMode::Both]);
    $hold = mrReservable();
    $cart = mrCart([[$both], [$hold]]);

    // «Корзина и резерв» became «Корзина»: the row can no longer be reserved, the choice is ignored for it.
    $both->update(['sales_mode' => SalesMode::Cart]);
    // «Резерв» became «Заявка»: the row leaves the cart.
    $hold->update(['sales_mode' => SalesMode::Inquiry]);

    try {
        app(OrderService::class)->checkoutFromCart($cart, mrMixedData(CheckoutChoice::Reservation));
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(1)->and($e->reasons[0])->toContain('больше недоступен');
    }

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData(CheckoutChoice::Reservation));

    expect($result->order->items->pluck('product_id')->all())->toBe([$both->id])->and($result->reservation)->toBeNull();
});

// ------------------------------------------------------------------ limits (MR-13)

it('limits the number of rows of one reservation (MR-13)', function (): void {
    sales(['reservation_max_items' => 2]);
    $cart = mrCart([[mrReservable()], [mrReservable()], [mrReservable()]]);

    expect(fn () => app(OrderService::class)->checkoutFromCart($cart, mrReserveData()))->toThrow(SalesException::class, 'не более 2 позиций');
    expect(Order::count())->toBe(0)->and($cart->items()->count())->toBe(3);

    $cart->items()->first()->delete();

    expect(app(OrderService::class)->checkoutFromCart($cart, mrReserveData())->reservation->items)->toHaveCount(2);
});

it('cuts a row above the limit of one position and tells the customer (MR-13, D-053)', function (): void {
    sales(['stock_tracking' => false]);
    $cart = mrCart([[mrReservable(['stock_qty' => null]), QtyLimit::MAX + 5]]);

    try {
        app(OrderService::class)->checkoutFromCart($cart, mrReserveData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons[0])->toContain('максимум для одной позиции');
    }

    expect((int) $cart->items()->value('qty'))->toBe(QtyLimit::MAX)->and(Order::count())->toBe(0);
});

it('counts the whole submit once against the rate limit (MR-13, SF-48)', function (): void {
    resetFormGuard();
    $cart = mrCart([[sellable(['sales_mode' => SalesMode::Cart])], [mrReservable()]]);

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component, 'samovyvoz')->call('submit')->assertHasNoErrors();

    expect(Order::count())->toBe(2)->and(RateLimiter::attempts('sales-form:127.0.0.1'))->toBe(1);
    resetFormGuard();
});

it('refuses the second parallel checkout of the same cart (one lock pass)', function (): void {
    $cart = mrCart([[mrReservable()]]);

    app(OrderService::class)->checkoutFromCart($cart, mrReserveData());

    expect(fn () => app(OrderService::class)->checkoutFromCart($cart, mrReserveData()))->toThrow(SalesException::class, 'Корзина пуста');
});

// ------------------------------------------------------------------ storefront (MR-03, MR-04, MR-05, MR-11)

it('shows «В резерв» as the main button with the link to the one-product form (MR-03)', function (): void {
    $reservation = mrReservable();
    $both = sellable(['sales_mode' => SalesMode::Both]);

    $this->get(route('product.show', $reservation->slug))->assertOk()
        ->assertSee('data-action="cart"', false)
        ->assertSeeText('В резерв')
        ->assertSeeText('Зарезервировать только этот товар')
        ->assertSee('data-action="reservation"', false);

    $this->get(route('product.show', $both->slug))->assertOk()
        ->assertSeeText('В корзину')
        ->assertSeeText('Зарезервировать только этот товар');

    sales(['reservation_via_cart' => false]);

    $this->get(route('product.show', $reservation->slug))->assertOk()
        ->assertDontSee('data-action="cart"', false)
        ->assertDontSeeText('В резерв')
        ->assertSee('data-action="reservation"', false)
        ->assertDontSeeText('Зарезервировать только этот товар');
});

it('adds a reservation product to the cart from the product page (MR-03)', function (): void {
    $product = mrReservable();

    livewireWithCart(AddToCart::class, null, ['productId' => $product->id])->call('add')->assertHasNoErrors();

    expect(CartItem::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('labels reservation rows and splits the blocks on the cart page (MR-04)', function (): void {
    $cart = mrCart([[sellable(['sales_mode' => SalesMode::Cart])], [mrReservable()], [sellable(['sales_mode' => SalesMode::Both])]]);

    livewireWithCart(CartPage::class, $cart)
        ->assertSeeHtml('data-line-kind="reservation"')
        ->assertSeeHtml('data-line-kind="flexible"')
        ->assertSeeHtml('data-cart-block="order"')
        ->assertSeeHtml('data-cart-block="reservation"')
        ->assertSee('Оформить заказ и резерв');

    // Only reservation rows: «Зарезервировать».
    livewireWithCart(CartPage::class, mrCart([[mrReservable()]]))->assertSee('Зарезервировать')->assertDontSee('Оформить заказ и резерв');
});

it('lets a flexible cart below the minimum reach the checkout where the choice is made (MR-08)', function (): void {
    sales(['min_order_amount' => 900000]);
    $cart = mrCart([[sellable(['sales_mode' => SalesMode::Both, 'base_price' => '100.00'])]]);

    livewireWithCart(CartPage::class, $cart)->assertSeeHtml('data-checkout>')->assertSeeHtml('data-min-order');
});

it('shows the choice switch, the explanation and the labels on the checkout page (MR-04, MR-05, MR-06)', function (): void {
    $both = sellable(['sales_mode' => SalesMode::Both]);
    $cart = mrCart([[$both], [sellable(['sales_mode' => SalesMode::Cart])]]);

    livewireWithCart(CheckoutForm::class, $cart)
        ->assertSeeHtml('data-checkout-choice')
        ->assertSeeHtml('data-checkout-block="order"')
        ->assertDontSeeHtml('data-reservation-fields')
        ->assertDontSeeHtml('data-mixed-note')
        ->assertSee('Подтвердить заказ')
        ->set('choice', 'reservation')
        ->assertSeeHtml('data-mixed-note')
        ->assertSee('Вы получите два номера')
        ->assertSee('Подтвердить заказ и резерв');

    // A reservation only: no delivery and payment, no explanation.
    livewireWithCart(CheckoutForm::class, mrCart([[mrReservable()]]))
        ->assertSeeHtml('data-reservation-fields')
        ->assertDontSeeHtml('data-mixed-note')
        ->assertDontSee('Способ оплаты')
        ->assertSee('Подтвердить резерв');
});

it('submits a pure reservation without delivery and payment and goes to the thanks page (MR-06, MR-11)', function (): void {
    resetFormGuard();
    $cart = mrCart([[mrReservable()], [mrReservable()]]);

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    $component->set('name', 'Иван Тестов')->set('phone', '8 (900) 123-45-67')->set('consent', true)->call('submit')->assertHasNoErrors();

    $reservation = Order::query()->firstOrFail();
    $component->assertRedirect(route('order.thanks', ['uuid' => $reservation->uuid]));

    expect($reservation->type)->toBe(OrderType::Reservation)->and($reservation->items)->toHaveCount(2);
    resetFormGuard();
});

it('sends the customer back to the cart with the shortage of a reservation (MR-07)', function (): void {
    resetFormGuard();
    $short = mrReservable(['stock_qty' => 2]);
    $cart = mrCart([[$short, 2]]);
    $short->forceFill(['reserved_qty' => 1])->save();

    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    $component->set('name', 'Иван Тестов')->set('phone', '8 (900) 123-45-67')->set('consent', true)->call('submit');

    $component->assertRedirect(route('cart'));
    expect(session('cart_shortages'))->toBe([(int) $cart->items()->value('id')])->and(Order::count())->toBe(0);
    resetFormGuard();
});

it('lists every request on the thanks page (MR-11)', function (): void {
    $result = app(OrderService::class)->checkoutFromCart(mrCart([[sellable(['sales_mode' => SalesMode::Cart, 'name' => 'Чайник'])], [mrReservable(['name' => 'Кресло'])]]), mrMixedData());

    $this->get(route('order.thanks', $result->order->uuid))->assertOk()
        ->assertSee($result->order->number)
        ->assertSee($result->reservation->number)
        ->assertSee('Чайник')
        ->assertSee('Кресло')
        ->assertSee('data-reserved-until', false)
        ->assertSee('data-two-numbers', false);

    // The reservation address shows the same page.
    $this->get(route('order.thanks', $result->reservation->uuid))->assertOk()->assertSee($result->order->number);
});

it('shows a single request on the thanks page as before', function (): void {
    $order = app(OrderService::class)->createFromCart(mrCart([[mrReservable()]]), mrReserveData());

    $this->get(route('order.thanks', $order->uuid))->assertOk()->assertDontSee('data-two-numbers', false)->assertSee('data-reserved-until', false);
});

// ------------------------------------------------------------------ notifications (MR-11)

function mrNotifications(): void
{
    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com'];
    $settings->telegram_enabled = true;
    $settings->telegram_bot_token = '123456:SECRET-token_abc';
    $settings->telegram_chat_id = '-1001';

    foreach (['notify_shop_email', 'notify_shop_telegram', 'notify_customer_new', 'notify_event_order', 'notify_event_reservation'] as $toggle) {
        $settings->{$toggle} = true;
    }

    $settings->save();
}

it('sends one letter to the customer with all requests and one Telegram message per request (MR-11)', function (): void {
    mrNotifications();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    $result = app(OrderService::class)->checkoutFromCart(mrCart([[sellable(['sales_mode' => SalesMode::Cart, 'name' => 'Чайник'])], [mrReservable(['name' => 'Кресло'])]]), mrMixedData());

    Mail::assertSent(OrderPlacedCustomerMail::class, 1);
    Mail::assertSent(OrderPlacedCustomerMail::class, function (OrderPlacedCustomerMail $mail) use ($result): bool {
        $html = $mail->render();

        return $mail->hasTo('ivan@example.com')
            && str_contains($html, $result->order->number) && str_contains($html, $result->reservation->number)
            && str_contains($html, 'Чайник') && str_contains($html, 'Кресло');
    });
    Http::assertSentCount(2);
});

it('sends a plain letter and message for a single request', function (): void {
    mrNotifications();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    app(OrderService::class)->createFromCart(mrCart([[mrReservable()], [mrReservable()]]), mrReserveData());

    Mail::assertSent(OrderPlacedCustomerMail::class, 1);
    Http::assertSentCount(1);
});

it('notifies about a reservation of several products with all items in the Telegram text', function (): void {
    $reservation = app(OrderService::class)->createFromCart(mrCart([[mrReservable(['name' => 'Кресло'])], [mrReservable(['name' => 'Стол'])]]), mrReserveData());

    $text = (new NewRequestMessage)->render($reservation);

    expect($text)->toContain('Кресло')->and($text)->toContain('Стол')->and($text)->toContain('Резерв до');
});

// ------------------------------------------------------------------ admin (MR-12)

it('shows the related request, the badge and the filter in the admin panel (MR-12)', function (): void {
    actingAs(User::factory()->admin()->create());
    $result = app(OrderService::class)->checkoutFromCart(mrCart([[sellable(['sales_mode' => SalesMode::Cart])], [mrReservable()]]), mrMixedData());
    $single = app(OrderService::class)->createFromCart(mrCart([[mrReservable()]]), mrReserveData());
    $plain = app(OrderService::class)->createReservation(mrReservable(), reservationData());

    Livewire::test(ListOrders::class)
        ->assertSee('связан с '.$result->order->number)
        ->assertSee('связан с '.$result->reservation->number)
        ->filterTable('reserved_via_cart', true)
        ->assertCanSeeTableRecords([$result->reservation, $single])
        ->assertCanNotSeeTableRecords([$result->order, $plain]);

    Livewire::test(ViewOrder::class, ['record' => $result->reservation->uuid])
        ->assertSee($result->order->number)
        ->assertSee('Резерв из корзины');

    expect(OrderQuery::apply(Order::query(), ['reserved_via_cart' => true])->count())->toBe(2);
});

it('shows both requests in the cabinet of a signed-in customer (MR-11)', function (): void {
    $customer = signInCustomer($this, makeCustomer());
    $this->get(route('customer.orders'))->assertOk();
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->beginRequest();

    $cart = Cart::factory()->create(['customer_id' => $customer->id]);
    CartItem::factory()->forProduct(sellable(['sales_mode' => SalesMode::Cart]), 1)->create(['cart_id' => $cart->id]);
    CartItem::factory()->forProduct(mrReservable(), 1)->create(['cart_id' => $cart->id]);

    $result = app(OrderService::class)->checkoutFromCart($cart, mrMixedData());

    expect($result->order->customer_id)->toBe($customer->id)->and($result->reservation->customer_id)->toBe($customer->id);

    $this->get(route('customer.orders'))->assertOk()->assertSee($result->order->number)->assertSee($result->reservation->number);
});

// ------------------------------------------------------------------ header icon and settings (23.3, 23.6)

it('shows the cart icon when only reservation products exist and the reservation goes through the cart (23.6)', function (): void {
    sales(['default_mode' => SalesMode::Reservation]);
    Cache::flush();
    Product::query()->delete();
    mrReservable();

    expect(app(CartAvailability::class)->enabled())->toBeTrue();

    sales(['reservation_via_cart' => false, 'default_mode' => SalesMode::Reservation]);
    Cache::flush();

    expect(app(CartAvailability::class)->enabled())->toBeFalse();
});

it('edits the reservation settings in the admin panel (MR-03, MR-13)', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(ManageSalesSettings::class)
        ->assertFormSet(['reservation_via_cart' => true, 'reservation_max_items' => 20])
        ->fillForm(['reservation_via_cart' => false, 'reservation_max_items' => 7])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SalesSettings::class);

    expect($settings->reservation_via_cart)->toBeFalse()->and($settings->reservation_max_items)->toBe(7);
});

it('aborts the checkout when a row moved to the other part after the page was shown (MR-02)', function (): void {
    resetFormGuard();
    $product = mrReservable(['stock_qty' => 5, 'name' => 'Лампа']);
    $cart = mrCart([[$product]]);

    $component = livewireWithCart(CheckoutForm::class, $cart);
    expect($component->get('shownTargets'))->toBe([$cart->items()->firstOrFail()->id => 'reservation']);

    $this->travel(5)->seconds();
    fillCheckout($component, 'samovyvoz');

    // The admin switches the product from «Резерв» to «Корзина» after the page was last shown.
    $product->update(['sales_mode' => SalesMode::Cart]);
    $component->call('submit');

    $component->assertRedirect(route('cart'));
    expect(Order::count())->toBe(0)
        ->and($cart->items()->count())->toBe(1)
        ->and($product->fresh()->stock_qty)->toBe(5)
        ->and(session('cart_changed')[0])->toContain('Лампа')->toContain('как заказ');
    resetFormGuard();
});

it('does not accept a shown split that differs from the server one at the service level (MR-02)', function (): void {
    $product = sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 5, 'name' => 'Чашка']);
    $cart = mrCart([[$product]]);
    $itemId = $cart->items()->firstOrFail()->id;
    $data = mrMixedData(CheckoutChoice::Reservation);
    $stale = new CheckoutData(
        $data->name, $data->phone, $data->email, $data->deliveryKey, $data->address, $data->paymentKey, $data->comment,
        $data->consent, $data->ip, $data->userAgent, choice: CheckoutChoice::Order, shownTargets: [$itemId => 'reservation'],
    );

    try {
        app(OrderService::class)->checkoutFromCart($cart, $stale);
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons[0])->toContain('Чашка')->toContain('как заказ');
    }

    expect(Order::count())->toBe(0);
});
