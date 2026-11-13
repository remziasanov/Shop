<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Exceptions\CartChangedException;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\AddToCart;
use App\Livewire\Storefront\CartPage;
use App\Mail\OrderPlacedCustomerMail;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Cart\CartMerger;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderService;

require_once __DIR__.'/../../Support/variant_storefront.php';

beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48]);
    $this->carts = app(CartService::class);
    $this->orders = app(OrderService::class);
    $this->cart = Cart::factory()->create();
    $this->shirt = vsShirt();
});

it('refuses a card with variants without a variant (VR-12)', function (): void {
    expect(fn () => $this->carts->add($this->cart, $this->shirt['card']))->toThrow(SalesException::class, 'Выберите модификацию')
        ->and(CartItem::count())->toBe(0);

    $this->postJson(route('cart.add'), ['product_id' => $this->shirt['card']->id])->assertStatus(422);
    expect(CartItem::count())->toBe(0);
});

it('refuses a foreign, inactive or deleted variant and a variant on a card without variants (VR-12)', function (): void {
    $other = vsShirt(['name' => 'Другая'], 'OT');
    $this->shirt['blueS']->forceFill(['is_active' => false])->saveQuietly();
    $plain = sellable();

    expect(fn () => $this->carts->add($this->cart, $this->shirt['card'], 1, $other['redS']->id))->toThrow(SalesException::class)
        ->and(fn () => $this->carts->add($this->cart, $this->shirt['card'], 1, $this->shirt['blueS']->id))->toThrow(SalesException::class)
        ->and(fn () => $this->carts->add($this->cart, $this->shirt['card'], 1, 999_999))->toThrow(SalesException::class)
        ->and(fn () => $this->carts->add($this->cart, $plain, 1, $other['redS']->id))->toThrow(SalesException::class)
        ->and(CartItem::count())->toBe(0);
});

it('keeps one row per variant with the price and the stock limit of the variant (VR-12)', function (): void {
    $card = $this->shirt['card'];

    $a = $this->carts->add($this->cart, $card, 2, $this->shirt['redS']->id);
    $this->carts->add($this->cart, $card, 1, $this->shirt['blueS']->id);
    $merged = $this->carts->add($this->cart, $card, 2, $this->shirt['redS']->id);
    $limited = $this->carts->add($this->cart, $card, 9, $this->shirt['blueS']->id);

    expect($merged->is($a))->toBeTrue()
        ->and($merged->qty)->toBe(4)
        ->and($limited->qty)->toBe(2)
        ->and($this->cart->items()->count())->toBe(2)
        ->and($a->variant_key)->toBe($this->shirt['redS']->id)
        ->and($a->price_at_add)->toBe('1000.00');

    // A variant without stock cannot be added.
    expect(fn () => $this->carts->add($this->cart, $card, 1, $this->shirt['redM']->id))->toThrow(SalesException::class, 'нет в наличии');
});

it('updates and removes the row of one variant only', function (): void {
    $card = $this->shirt['card'];
    $this->carts->add($this->cart, $card, 1, $this->shirt['redS']->id);
    $this->carts->add($this->cart, $card, 1, $this->shirt['blueS']->id);

    $this->carts->update($this->cart, $card->id, 3, $this->shirt['redS']->id);
    expect($this->cart->items()->where('variant_id', $this->shirt['redS']->id)->value('qty'))->toBe(3);

    $this->carts->remove($this->cart, $card->id, $this->shirt['redS']->id);
    expect($this->cart->items()->pluck('variant_id')->all())->toBe([$this->shirt['blueS']->id]);
});

it('shows the variant label and its current price in the summary and flags a deactivated variant', function (): void {
    $card = $this->shirt['card'];
    $this->carts->add($this->cart, $card, 1, $this->shirt['redS']->id);
    $this->shirt['redS']->update(['base_price' => '1100.00']);

    $line = $this->carts->summary($this->cart)->lines[0];

    expect($line->variantLabel)->toBe('Цвет: Красный, Размер: S')
        ->and($line->currentPrice)->toBe(110000)
        ->and($line->priceChanged)->toBeTrue();

    $this->shirt['redS']->forceFill(['is_active' => false])->saveQuietly();
    $line = $this->carts->summary($this->cart)->lines[0];

    expect($line->unavailable)->toBeTrue()->and($line->lineTotal)->toBe(0);
});

it('lists the variant on the cart page with its label and a link that preselects it', function (): void {
    $this->carts->add($this->cart, $this->shirt['card'], 2, $this->shirt['redS']->id);

    livewireWithCart(CartPage::class, $this->cart)
        ->assertSee('Футболка')
        ->assertSee('Цвет: Красный, Размер: S')
        ->assertSeeHtml('?variant='.$this->shirt['redS']->id)
        ->set('qtys.'.$this->shirt['card']->id.'_'.$this->shirt['redS']->id, 4);

    expect($this->cart->items()->value('qty'))->toBe(4);

    livewireWithCart(CartPage::class, $this->cart)->call('remove', $this->shirt['card']->id, $this->shirt['redS']->id);
    expect($this->cart->items()->count())->toBe(0);
});

it('adds from the product page component only with a valid variant (VR-12)', function (): void {
    $card = $this->shirt['card'];
    $cart = Cart::factory()->create();

    livewireWithCart(AddToCart::class, $cart, ['productId' => $card->id])
        ->call('add')
        ->assertHasErrors('cart');

    livewireWithCart(AddToCart::class, $cart, ['productId' => $card->id])
        ->set('variantId', 999_999)
        ->call('add')
        ->assertHasErrors('cart');

    expect($cart->items()->count())->toBe(0);

    livewireWithCart(AddToCart::class, $cart, ['productId' => $card->id, 'variantId' => $this->shirt['blueS']->id])
        ->call('add')
        ->assertHasNoErrors();

    expect($cart->items()->first()->variant_id)->toBe($this->shirt['blueS']->id);
});

it('merges the guest cart into the customer cart per variant (VR-12)', function (): void {
    $card = $this->shirt['card'];
    $customer = Customer::factory()->create();
    $own = Cart::factory()->create(['customer_id' => $customer->id]);
    $guest = Cart::factory()->create();

    CartItem::factory()->create(['cart_id' => $own->id, 'product_id' => $card->id, 'variant_id' => $this->shirt['redS']->id, 'qty' => 1, 'price_at_add' => '1000.00']);
    CartItem::factory()->create(['cart_id' => $guest->id, 'product_id' => $card->id, 'variant_id' => $this->shirt['redS']->id, 'qty' => 2, 'price_at_add' => '1000.00']);
    CartItem::factory()->create(['cart_id' => $guest->id, 'product_id' => $card->id, 'variant_id' => $this->shirt['blueS']->id, 'qty' => 9, 'price_at_add' => '1000.00']);

    app(CartMerger::class)->merge($customer, $guest->token);

    $rows = CartItem::query()->where('cart_id', $own->id)->orderBy('variant_id')->get()->pluck('qty', 'variant_id')->all();

    // Same variant summed, the other one added and limited by its own stock (2).
    expect($rows)->toBe([$this->shirt['redS']->id => 3, $this->shirt['blueS']->id => 2])
        ->and(Cart::query()->whereKey($guest->id)->exists())->toBeFalse();
});

it('checks out a variant: snapshot, variant and aggregate stock (VR-12, VR-13, VR-15)', function (): void {
    $card = $this->shirt['card'];
    $this->carts->add($this->cart, $card, 2, $this->shirt['redS']->id);
    $this->carts->add($this->cart, $card, 1, $this->shirt['blueS']->id);

    $order = $this->orders->createFromCart($this->cart, checkoutData());

    $item = $order->items->firstWhere('variant_id', $this->shirt['redS']->id);

    expect($order->items)->toHaveCount(2)
        ->and($item->sku)->toBe('TS-RED-S')
        ->and($item->variant_label)->toBe('Цвет: Красный, Размер: S')
        ->and($item->barcode)->toBe('4600000000011')
        ->and($item->name)->toBe('Футболка')
        ->and($item->price)->toBe('1000.00')
        ->and($item->total)->toBe('2000.00')
        ->and($item->stock_applied_qty)->toBe(2)
        ->and($order->items_total)->toBe('3000.00');

    expect($this->shirt['redS']->fresh()->stock_qty)->toBe(3)
        ->and($this->shirt['blueS']->fresh()->stock_qty)->toBe(1)
        ->and($this->shirt['redM']->fresh()->stock_qty)->toBe(0)
        ->and($card->fresh()->stock_qty)->toBe(4)
        ->and($this->cart->items()->count())->toBe(0);

    // The snapshot survives a later change of the variant (VR-12).
    $this->shirt['redS']->update(['sku' => 'TS-RED-S2', 'barcode' => null]);
    expect($item->fresh()->sku)->toBe('TS-RED-S');

    // Cancelling returns the stock to that variant (VR-15).
    $this->orders->changeStatus($order, OrderStatus::Cancelled);
    expect($this->shirt['redS']->fresh()->stock_qty)->toBe(5)->and($card->fresh()->stock_qty)->toBe(7);
});

it('re-checks the price of the variant at checkout and brings the cart up to date (PR-07, VR-13)', function (): void {
    $this->carts->add($this->cart, $this->shirt['card'], 1, $this->shirt['redS']->id);
    $this->shirt['redS']->update(['base_price' => '1300.00']);

    try {
        $this->orders->createFromCart($this->cart, checkoutData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(1)->and($e->reasons[0])->toContain('Футболка')->and($e->reasons[0])->toContain('Красный');
    }

    expect(Order::count())->toBe(0)->and($this->cart->items()->first()->price_at_add)->toBe('1300.00');

    $order = $this->orders->createFromCart($this->cart, checkoutData());
    expect($order->items->first()->price)->toBe('1300.00');
});

it('re-checks the stock of the variant at checkout: reduces, then removes (VR-13)', function (): void {
    $card = $this->shirt['card'];
    $this->carts->add($this->cart, $card, 4, $this->shirt['redS']->id);
    $this->shirt['redS']->forceFill(['stock_qty' => 3])->saveQuietly();

    expect(fn () => $this->orders->createFromCart($this->cart, checkoutData()))->toThrow(CartChangedException::class)
        ->and($this->cart->items()->first()->qty)->toBe(3);

    $this->shirt['redS']->forceFill(['stock_qty' => 0])->saveQuietly();

    expect(fn () => $this->orders->createFromCart($this->cart, checkoutData()))->toThrow(CartChangedException::class)
        ->and($this->cart->items()->count())->toBe(0)
        ->and(Order::count())->toBe(0);
});

it('drops a cart row whose variant was deactivated or deleted before checkout (VR-13)', function (): void {
    $this->carts->add($this->cart, $this->shirt['card'], 1, $this->shirt['redS']->id);
    $this->shirt['redS']->delete();

    expect(fn () => $this->orders->createFromCart($this->cart, checkoutData()))->toThrow(CartChangedException::class)
        ->and($this->cart->items()->count())->toBe(0);
});

it('takes the sales mode from the card while availability comes from the variant (VR-13)', function (): void {
    $this->carts->add($this->cart, $this->shirt['card'], 1, $this->shirt['redS']->id);
    $this->shirt['card']->update(['sales_mode' => SalesMode::Inquiry]);

    expect(fn () => $this->orders->createFromCart($this->cart, checkoutData()))->toThrow(CartChangedException::class);
});

it('reserves and releases the stock of one variant and keeps the aggregate (VR-14, VR-15)', function (): void {
    $card = $this->shirt['card'];

    $order = $this->orders->createReservation($card, reservationData(2), $this->shirt['redS']->id);
    $item = $order->items->first();

    expect($order->type)->toBe(OrderType::Reservation)
        ->and($item->variant_id)->toBe($this->shirt['redS']->id)
        ->and($item->sku)->toBe('TS-RED-S')
        ->and($item->variant_label)->toBe('Цвет: Красный, Размер: S')
        ->and($item->price)->toBe('1000.00')
        ->and($this->shirt['redS']->fresh()->reserved_qty)->toBe(2)
        ->and($this->shirt['blueS']->fresh()->reserved_qty)->toBe(0)
        ->and($card->fresh()->reserved_qty)->toBe(2)
        ->and($card->fresh()->stock_qty)->toBe(7);

    $this->orders->changeStatus($order, OrderStatus::Cancelled);

    expect($this->shirt['redS']->fresh()->reserved_qty)->toBe(0)->and($card->fresh()->reserved_qty)->toBe(0);
});

it('refuses a reservation or inquiry without a valid variant and over the variant stock (VR-14)', function (): void {
    $card = $this->shirt['card'];

    expect(fn () => $this->orders->createReservation($card, reservationData(1)))->toThrow(SalesException::class, 'Выберите модификацию')
        ->and(fn () => $this->orders->createInquiry($card, inquiryData()))->toThrow(SalesException::class, 'Выберите модификацию')
        ->and(fn () => $this->orders->createReservation($card, reservationData(6), $this->shirt['redS']->id))->toThrow(SalesException::class)
        ->and(fn () => $this->orders->createReservation($card, reservationData(1), 999_999))->toThrow(SalesException::class)
        ->and(Order::count())->toBe(0);
});

it('creates an inquiry for an out-of-stock variant with the same snapshot (VR-14)', function (): void {
    $order = $this->orders->createInquiry($this->shirt['card'], inquiryData(), $this->shirt['redM']->id);
    $item = $order->items->first();

    expect($order->type)->toBe(OrderType::Inquiry)
        ->and($item->variant_id)->toBe($this->shirt['redM']->id)
        ->and($item->variant_label)->toBe('Цвет: Красный, Размер: M')
        ->and($item->price)->toBe('1200.00')
        ->and($this->shirt['redM']->fresh()->reserved_qty)->toBe(0);
});

it('shows the label of the variant in the order mail items and the thank-you page', function (): void {
    $this->carts->add($this->cart, $this->shirt['card'], 1, $this->shirt['redS']->id);
    $order = $this->orders->createFromCart($this->cart, checkoutData());

    expect($order->items->first()->displayName())->toBe('Футболка (Цвет: Красный, Размер: S)');

    $this->get(route('order.thanks', $order->uuid))->assertOk()->assertSee('Футболка (Цвет: Красный, Размер: S)');
    expect((new OrderPlacedCustomerMail($order))->render())->toContain('Цвет: Красный, Размер: S');
});
